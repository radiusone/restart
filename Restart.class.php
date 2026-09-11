<?php
namespace FreePBX\modules;

use DateTime;
use Exception;
use FreePBX;
use FreePBX\Ajax;
use AGI_AsteriskManager;
use Cron\CronExpression;
use FreePBX\BMO;
use FreePBX\FreePBX_Helpers as Helper;
use FreePBX\modules\Restart\Job;
use Ramsey\Uuid\Uuid;

use function in_array;
use function is_array;
use function is_null;
use function is_string;
use function sprintf;

class Restart extends Helper implements BMO
{
    public const MODULE_NAME = "restart";

    private FreePBX $FreePBX;

    private static array $messages = [
        "aastra"      => "aastra-check-cfg",
        "cisco"       => "cisco-check-cfg",
        "grandstream" => "grandstream-check-cfg",
        "poly"        => "polycom-check-cfg",
        "snom"        => "reboot-snom",
        "yealink"     => "reboot-yealink",
    ];

    /**
     * When receiving ajax requests, a FreePBX instance is not passed for some reason
     */
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
        $jobname = $request["itemid"] ?? "";
        if (method_exists($this, $command)) {
            return $this->$command($jobname);
        }

        return ["status"=>false, "message"=>_("Unknown command")];
    }

    /**
     * Return the main page content
     */
    public function showPage(): string
    {
        $txtinfo = sprintf(
            '<div class="alert alert-info">%s</div>',
            htmlspecialchars(_("Currently, only Aastra, Snom, Polycom, Grandstream and Cisco devices are supported."))
        );

        if (is_array($_POST["restartlist"] ?? null)) {
            $restartlist = $_POST['restartlist'];
            if (($_POST["enable_schedule"] ?? "0") === "0") {
                foreach($restartlist as $device) {
                    Restart::restartDevice($device);
                    $txtinfo = sprintf(
                        '<div class="alert alert-info">%s</div>',
                        htmlspecialchars(_("Restart requests sent!"))
                    );
                }
            } else {
                $schedtime = $_POST["schedtime"];
                [$schedhour, $schedmin] = explode(":", $schedtime);
                $schedmonth = $_POST["schedmonth"] ?? "*";
                $schedday = $_POST["schedday"] ?? "*";
                $scheddow = $_POST["scheddow"] ?? "*";
                $custom_expr = $_POST["schedexpression"] ?? null;
                $recurring = ($_POST["schedrecurring"] ?? "0") === "1" || !is_null($custom_expr);

                try {
                    $schedule = CronExpression::factory($custom_expr ?? "* * * * *");
                    if (is_null($custom_expr)) {
                        $schedule->setPart(CronExpression::MINUTE, $schedmin);
                        $schedule->setPart(CronExpression::HOUR, $schedhour);
                        $schedule->setPart(CronExpression::DAY, $schedday);
                        $schedule->setPart(CronExpression::MONTH, $schedmonth);
                        $schedule->setPart(CronExpression::WEEKDAY, $scheddow);    
                    }
                    foreach ($restartlist as $device) {
                        $this->scheduleRestart($device, $schedule, $recurring);
                    }
                    $txtinfo = sprintf(
                        '<div class="alert alert-info">%s</div>',
                        htmlspecialchars(_("Restart requests scheduled!"))
                    );
                } catch (Exception) {
                    $txtinfo = sprintf(
                        '<div class="alert alert-danger">%s</div>',
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

    public function listJobs(): array
    {
        $return = [];
        $job = FreePBX::Job();
        $jobs = array_filter(
            $job->getAll(),
            fn($v) => $v["modulename"] === self::MODULE_NAME
        );
        foreach ($jobs as $job) {
            $time = $job["schedule"];
            $jobname = $job["jobname"];
            $time = self::cronToHuman($time, recurring: str_starts_with($jobname, "recurring"));

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
    }

    /**
     * Delete a named job
     */
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

    /**
     * Save the scheduled restart as a job and also as a module config setting
     * 
     * @param string $device the extension to restart
     * @param CronExpression $schedule the cron schedule
     * @param bool $recurring if true, the job and config are retained after running, to be run again 
     */
    public function scheduleRestart(string $device, CronExpression $schedule, bool $recurring = false): bool
    {
        $uuid = Uuid::uuid4();
        $jobname = sprintf("%s_%s", $recurring ? "recurring" : "scheduled", $uuid);
        $job = FreePBX::Job();
        $job->remove(self::MODULE_NAME, $jobname);
        $job->addClass(
            self::MODULE_NAME,
            $jobname,
            Job::class,
            $schedule->getExpression()
        );

        return $this->setConfig($jobname, $device);
    }

    /**
     * Get an extension's user-agent string
     * 
     * @param string $device the extension number
     */
    public static function getUserAgent(string $device): string
    {
        $astman = FreePBX::astman();
        $agents = array_keys(self::$messages);

        // can't do a wildcard search through the cache
        $astman->useCaching = false;
        $command = sprintf("registrar/contact/%d%%", $device);
        $responses = $astman->database_show($command);
        foreach ($responses as $data) {
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

    /**
     * Send a SIP notify command
     * 
     * @param string $event the name of the SIP event
     * @param string $device the extension number
     */
    private static function sipNotify(string $event, string $device): bool
    {
        /** @var AGI_AsteriskManager $astman  */
        $astman = FreePBX::astman();
        $command = sprintf("pjsip send notify %s endpoint %s", $event, $device);
        $result = $astman->command($command);

        return $result["Response"] === "Success";
    }

    /**
     * Convert a cron expression to a human readable text
     * 
     * @param string|CronExpression $cron
     * @param bool $recurring determines if return looks like "every ..." or "next ..."
     * @param string|null $date_format defaults to "j M Y" if null
     * @param string|null $time_format defaults to "g:i: a" if null
     */
    private static function cronToHuman(string|CronExpression $cron, bool $recurring, string|null $date_format = null, string|null $time_format = null): string
    {
        if (is_string($cron)) {
            $cron = CronExpression::factory($cron);
        }
        $date_format ??= _("j M Y");
        $short_date_format = trim(str_replace("Y", "", $date_format));
        $time_format ??= _("g:i a");

        $now = new DateTime();
        $time = null;
        [$minute, $hour, $day, $month, $dow] = explode(" ", $cron);
        if (is_numeric($minute)) {
            $minute = sprintf("%02d", $minute);
        }
        if (is_numeric($hour)) {
            $hour = sprintf("%02d", $hour);
        }
        if (is_numeric($day)) {
            $day = sprintf("%02d", $day);
        }
        if (is_numeric($month)) {
            $month = sprintf("%02d", $month);
        }
        if (is_numeric($dow)) {
            $dow = (new DateTime("sunday + $dow days"))->format("l");
        } else {
            $dow = _("day");
        }
        $skip = preg_match("~[,/@]~", $cron) || $hour === "*" || $minute === "*";

        if ($recurring && !$skip) {
            if ("$day$month" === "**") {
                $dt = Datetime::createFromFormat("Hi", "$hour$minute");
                $time = sprintf(_("Every %s at %s"), $dow, $dt->format($time_format));
            } elseif ($month === "*") {
                $dt = Datetime::createFromFormat("Hi d", "$hour$minute $day");
                $time = sprintf(
                    _("%s of every month at %s"),
                    $dt->format(_("jS")),
                    $dt->format($time_format)
                );
            } elseif ($day === "*") {
                $dt = Datetime::createFromFormat("Hi m", "$hour$minute $month");
                $time = sprintf(
                    _("Every %s in %s at %s"),
                    $dow,
                    $dt->format(_("F")),
                    $dt->format($time_format)
                );
            } elseif ($dow === _("day")) {
                $dt = Datetime::createFromFormat("Hi m d", "$hour$minute $month $day");
                $time = sprintf(
                    _("Every year on %s at %s"),
                    $dt->format($short_date_format),
                    $dt->format($time_format)
                );
            }
        } elseif (!$recurring && !$skip) {
            if ("$day$month" === "**") {
                $dt = Datetime::createFromFormat("Hi", "$hour$minute");
                $time = sprintf(
                    _("%s at %s"),
                    $dow === _("day") ? ($dt < $now ? _("Tomorrow") : _("Today")) : sprintf(_("Next %s"), $dow),
                    $dt->format($time_format)
                );
            } elseif ($month === "*") {
                // check if it's this month or next
                $dt = Datetime::createFromFormat("Hi d", "$hour$minute $day");
                if ($now > $dt) {
                    $dt->modify("+1 month");
                }
                $time = sprintf(
                    "%s at %s",
                    $dt->format("md") < $now->format("md")
                        ? $dt->modify("+1 year")->format($date_format)
                        : $dt->format($short_date_format),
                    $dt->format($time_format)
                );
            } elseif ($day === "*") {
                $dt = Datetime::createFromFormat("m d Hi", "$month 1 $hour$minute");
                $time = sprintf(
                    "%s at %s",
                    $dt->format("md") < $now->format("md")
                        ? $dt->modify("+1 year")->format($date_format)
                        : $dt->format($short_date_format),
                    $dt->format($time_format)
                );
            } elseif ($dow === _("day")) {
                $dt = Datetime::createFromFormat("m d Hi", "$month $day $hour$minute");
                $time = sprintf(
                    "%s at %s",
                    $dt->format("md") < $now->format("md")
                        ? $dt->modify("+1 year")->format($date_format)
                        : $dt->format($short_date_format),
                    $dt->format($time_format)
                );
            }
        }

        // something complicated, I cba parsing it
        $time ??= sprintf(_("Custom cron schedule: %s"), $cron);

        return $time;
    }
}
