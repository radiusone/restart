<?php
namespace FreePBX\modules;

use DateTime;
use FreePBX;
use FreePBX\Ajax;
use AGI_AsteriskManager;
use FreePBX\BMO;
use FreePBX\FreePBX_Helpers as Helper;
use FreePBX\modules\Restart\Job;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Console\Output\OutputInterface;

class Restart extends Helper implements BMO
{
    const MODULE_NAME = "restart";

    private FreePBX $FreePBX;

    private static array $messages = [
        "aastra"      => "aastra-check-cfg",
        "cisco"       => "cisco-check-cfg",
        "grandstream" => "grandstream-check-cfg",
        "poly"        => "polycom-check-cfg",
        "snom"        => "reboot-snom",
        "yealink"     => "reboot-yealink",
    ];

    public function __construct(FreePBX|Ajax $freepbx)
    {
        $this->FreePBX = $freepbx instanceof FreePBX ? $freepbx : FreePBX::create();
    }

    public function install() {}

    public function uninstall() {}

    public function backup() {}

    public function restore($backup) {}

    public function doConfigPageInit($page) {}

    public function getActionBar(array $request): array
    {
        $buttons = [
            'submit' => [
                'name' => 'submit',
                'id' => 'submit',
                'value' => _('Restart Phones')
            ],
        ];

        return $buttons;
    }

    /**
     * This function is run when configuration is generated
     *
     * "When the 'reload' button is clicked, genConfig will be called, the output will
     * be given to any modules that requested it, and what they return will then be
     * given to writeConfig."
     *
     * @see \FreePBX\FileHooks::processNewHooks()
     * @return array<string,array<string,array<string,string>>> with filename=>section=>contents
     */
    public function genConfig(): array
    {
        $conf = [
            "sip_notify_additional.conf" => [
                "aastra-check-cfg"       => ["Event" => "check-sync"],
                "aastra-xml"             => ["Event" => "aastra-xml"],
                "algo-check-cfg"         => ["Event" => "check-sync"],
                "audiocodes-check-cfg"   => ["Event" => "check-sync"],
                "cisco-check-cfg"        => ["Event" => "check-sync"],
                "cyberdata-check-cfg"    => ["Event" => "check-sync"],
                "grandstream-check-cfg"  => ["Event" => "check-sync"],
                "linksys-cold-restart"   => ["Event" => "reboot_now"],
                "linksys-warm-restart"   => ["Event" => "restart_now"],
                "panasonic-check-cfg"    => ["Event" => "check-sync"],
                "polycom-check-cfg"      => ["Event" => "check-sync"],
                "reboot-snom"            => ["Event" => "reboot"],
                "reboot-yealink"         => ["Event" => "check-sync\\;reboot=false"],
                "sipura-check-cfg"       => ["Event" => "resync"],
                "spa-reboot"             => ["Event" => "reboot"],
            ],
        ];

        return $conf;
    }

    /**
     * This function is run when configuration is applied
     *
     * @see \FreePBX\FileHooks::processNewHooks()
     * @param array $config The configuration object (returned from genConfig)
     * @return void
     */
    public function writeConfig(array $config): void
    {
        $this->FreePBX->WriteConfig($config);
    }

    /**
     * Ajax request check; confirm command is okay and optionally pass some settings
     *
     * @see \FreePBX\Ajax::doRequest()
     * @param string $command The command name
     * @param array $setting Settings to return back
     * @return bool
     */
    public function ajaxRequest(string $command, array &$setting): bool
    {
        return in_array($command, ["listJobs", "deleteJob"]);
    }

    /**
     * Handle the ajax request, passed in $_REQUEST["command"]
     *
     * @see \FreePBX\Ajax::doRequest()
     * @return array{status:bool, message:string}|list<array<string,string>> The result of the command
     */
    public function ajaxHandler()
    {
        $request = $_REQUEST;
        $command = $request["command"] ?? "";
        if ($command === "listJobs") {
            $return = [];
            $job = FreePBX::Job();
            $jobs = array_filter(
                $job->getAll(),
                fn($v) => $v["modulename"] === self::MODULE_NAME
            );
            $now = new Datetime();
            foreach ($jobs as $job) {
                $sched = explode(" ", $job["schedule"]);
                $time = $job["schedule"];
                $minute = $sched[0];
                $hour = $sched[1];
                $day = $sched[2];
                $month = $sched[3];
                $jobname = $job["jobname"];
                if (str_starts_with($jobname, "recurring")) {
                    if ("$day$month" === "**") {
                        $dt = Datetime::createFromFormat("Hi", "$hour$minute");
                        $time = sprintf(_("Every day at %s"), $dt->format(_("g:i a")));
                    } elseif ($month === "*") {
                        $dt = Datetime::createFromFormat("Hi j", "$hour$minute $day");
                        $time = sprintf(
                            _("%s of every month at %s"),
                            $dt->format(_("jS")),
                            $dt->format(_("g:i a"))
                        );
                    } elseif ($day === "*") {
                        $dt = Datetime::createFromFormat("Hi n", "$hour$minute $month");
                        $time = sprintf(
                            _("Every day in %s at %s"),
                            $dt->format(_("F")),
                            $dt->format(_("g:i a"))
                        );
                    } else {
                        $dt = Datetime::createFromFormat("Hi n j", "$hour$minute $month $day");
                        $time = sprintf(
                            _("Every year on %s at %s"),
                            $dt->format(_("j M")),
                            $dt->format(_("g:i a"))
                        );
                    }
                } elseif ("$day$month" === "**") {
                    $dt = Datetime::createFromFormat("Hi", "$hour$minute");
                    $time = sprintf(
                        _("%s at %s"),
                        $dt < $now ? _("Tomorrow") : _("Today"),
                        $dt->format(_("g:i a"))
                    );
                } elseif ($month === "*") {
                    // check if it's this month or next
                    $dt = Datetime::createFromFormat("Hi j", "$hour$minute $day");
                    if ($now > $dt) {
                        $dt->modify("+1 month");
                    }
                    $time = sprintf(
                        "%s at %s",
                        $dt->format("md") < $now->format("md")
                            ? $dt->modify("+1 year")->format(_("j M Y"))
                            : $dt->format(_("j M")),
                        $dt->format(_("g:i a"))
                    );
                } elseif ($day === "*") {
                    $dt = Datetime::createFromFormat("n j Hi", "$month 1 $hour$minute");
                    $time = sprintf(
                        "%s at %s",
                        $dt->format("md") < $now->format("md")
                            ? $dt->modify("+1 year")->format(_("j M Y"))
                            : $dt->format(_("j M")),
                        $dt->format(_("g:i a"))
                    );
                } else {
                    $dt = Datetime::createFromFormat("n j Hi", "$month $day $hour$minute");
                    $time = sprintf(
                        "%s at %s",
                        $dt->format("md") < $now->format("md")
                            ? $dt->modify("+1 year")->format(_("j M Y"))
                            : $dt->format(_("j M")),
                        $dt->format(_("g:i a"))
                    );
                }
                if ($devices = $this->getConfig($jobname)) {
                    $devices = is_array($devices) ? implode(", ", $devices) : $devices;
                } else {
                    $devices = _("None (invalid entry)");
                }
                $return[] = [
                    "jobname" => $jobname,
                    "time" => $time,
                    "devices" => $devices,
                ];
            }

            return $return;
        } elseif ($command === "deleteJob") {
            $jobname = $_GET["itemid"];

            return $this->deleteJob($jobname);
        }

        return ["status"=>false, "message"=>_("Unknown command")];
    }

    public function showPage(): string
    {
        $txtinfo = sprintf(
            '<div class="well well-info">%s</div>',
            htmlspecialchars(_("Currently, only Aastra, Snom, Polycom, Grandstream and Cisco devices are supported."))
        );

        if (is_array($_POST["restartlist"] ?? null)) {
            $restartlist = $_POST['restartlist'];
            if (empty($_POST["schedtime"])) {
                foreach($restartlist as $device) {
                    Restart::restartDevice($device);
                    $txtinfo = sprintf(
                        '<div class="well well-info">%s</div>',
                        htmlspecialchars(_("Restart requests sent!"))
                    );
                }
            } else {
                $schedtime = $_POST["schedtime"];
                $schedmonth = $_POST["schedmonth"];
                $schedday = $_POST["schedday"];
                $recurring = !empty($_POST["schedrecurring"]);
                if ($schedmonth === "*") {
                    $format = ($schedday === "*" ? "*-* H:i" : "*-d H:i");
                } elseif ($schedday === "*") {
                    $format = "m-* H:i";
                } else {
                    $format = "m-d H:i";
                }
                $date = Datetime::createFromFormat($format, "$schedmonth-$schedday $schedtime");
                if ($date) {
                    foreach ($restartlist as $device) {
                        $this->scheduleRestart($device, $schedtime, $schedmonth, $schedday, $recurring);
                    }
                    $txtinfo = sprintf(
                        '<div class="well well-info">%s</div>',
                        htmlspecialchars(_("Restart requests scheduled!"))
                    );
                } else {
                    $txtinfo = sprintf(
                        '<div class="well well-error">%s</div>',
                        htmlspecialchars(_("An invalid schedule was provided."))
                    );
                }
            }
        }

        $device_list = [];
        foreach (FreePBX::Core()->getAllDevicesByType() as $device) {
            $ua = ucfirst(self::getUserAgent($device["id"]));
            if ($ua) {
                $device["ua"] = $ua;
                $device_list[] = $device;
            }
        }

        return load_view(__DIR__ . "/views/page.restart.php", compact("txtinfo", "device_list")) ?: "";
    }

    /**
     * Run a named job
     * 
     * @param bool $delete if true, the job will be deleted after running
     */
    public function runJob(OutputInterface $output, string $jobname, bool $delete = false): void
    {
        if ($devicelist = $this->getConfig($jobname)) {
            if (!is_array($devicelist)) {
                $devicelist = [$devicelist];
            }
            foreach ($devicelist as $device) {
                $output->write(sprintf(_("Sending restart request for %s..."), $device));
                $result = self::restartDevice($device);
                $output->writeln($result ? _("success") : _("error"));
            }
        }
        if ($delete !== true) {
            return;
        }
        $this->deleteJob($jobname);
    }

    private function deleteJob(string $jobname): array
    {
        $this->delConfig($jobname);
        $job = FreePBX::Job();
        $result = $job->remove(self::MODULE_NAME, $jobname);

        return [$result];
    }

    public static function restartDevice(string $device): bool
    {
        $ua = self::getUserAgent($device);
        if ($ua === "") {
            return false;
        }

        return self::sipNotify(self::$messages[$ua], $device);
    }

    public function scheduleRestart(string $device, string $schedtime, string $schedmonth, string $schedday, bool $recurring = false): bool
    {
        list($hour, $min) = explode(":", $schedtime);
        $uuid = Uuid::uuid4();
        $jobname = sprintf(
            "%s_reboot_%s_%s_%s%s_%s",
            ($recurring ? "recurring" : "scheduled"),
            $schedmonth,
            $schedday,
            $hour,
            $min,
            $uuid
        );
        $schedule = "$min $hour $schedday $schedmonth *";
        $job = FreePBX::Job();
        $job->remove(self::MODULE_NAME, $jobname);
        $job->addClass(
            self::MODULE_NAME,
            $jobname,
            Job::class,
            $schedule
        );

        return $this->setConfig($jobname, $device);
    }

    public static function getUserAgent(string $device): string
    {
        $astman = FreePBX::astman();
        $agents = array_keys(self::$messages);

        // can't do a wildcard search through the cache
        $astman->useCaching=false;
        $command = sprintf("registrar/contact/%d%%", $device);
        $responses = $astman->database_show($command);
        foreach ($responses as $contact => $data) {
            $data = json_decode($data, true);
            if (!empty($data["user_agent"])) {
                $ua = $data["user_agent"];
                $result = array_filter($agents, function ($v) use ($ua){
                    return preg_match("/\\b$v/i", $ua);
                });
                return array_pop($result) ?? "";
            }
        }

        return "";
    }

    private static function sipNotify(string $event, string $device): bool
    {
        /** @var AGI_AsteriskManager $astman  */
        $astman = FreePBX::astman();
        $command = sprintf("pjsip send notify %s endpoint %s", $event, $device);
        $result = $astman->command($command);

        return $result["Response"] === "Success";
    }
}
