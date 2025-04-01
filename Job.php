<?php
namespace FreePBX\modules\Restart;

use Cron\CronExpression;
use FreePBX;
use FreePBX\Job as BmoJob;
use FreePBX\Job\TaskInterface;
use FreePBX\modules\Restart;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `fwconsole job --run` is run every minute, all jobs are checked and
 * if the time matches, this method is called – but for some reason isn't
 * passed the job info. So we need to run through all the jobs ourself and
 * run any that need to be run.
 * 
 * `fwconsole job --run 23` can also be called to run a specific job.
 */
class Job implements TaskInterface {
    public static function run(InputInterface $input, OutputInterface $output) {
        $output->writeln("");
        $id = (int)$input->getOption("run");
        /** @var BmoJob $module */
        $module = FreePBX::Job();
        foreach ($module->getAll() as $row) {
            if ($row["modulename"] !== "restart") {
                continue;
            }
            // specific job called
            if ((int)$row["id"] === $id && ($row["enabled"] || $input->getOption("force"))) {
                return self::runJob($row, $output);
            }
            // running from cron, have to check which jobs are supposed to be run now
            if (CronExpression::factory($row["schedule"])->isDue() || $input->getOption("force")) {
                self::runJob($row, $output);
            }
        }
        return true;
    }

    /**
     * @param array{id|modulename|jobname|command|class|schedule|max_runtime|enabled|execution_order: string} $config
     */
    public static function runJob(array $config, InputInterface $input, OutputInterface $output)
    {
        $jobname = $config["jobname"];
        $output->writeln(sprintf(_("Starting phone restarts for job %s..."), $jobname));
        /** @var Restart $module */
        $module = FreePBX::Restart();
        $module->runJob($output, $jobname, delete: !str_starts_with($jobname, "recurring"));
        $output->writeln(_("Finished"));

        return true;
    }
}
