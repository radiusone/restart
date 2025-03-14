<?php
namespace FreePBX\modules\Restart;

use \Symfony\Component\Console\Output\OutputInterface;
use \Symfony\Component\Console\Input\InputInterface;
use \FreePBX\Job\TaskInterface;
use \FreePBX;

class Job implements TaskInterface {
    public static function run(InputInterface $input, OutputInterface $output) {
        $output->writeln("");
        $id = (int)$input->getOption("run");
        $job = FreePBX::Job();
        $jobname = "";
        foreach ($job->getAllEnabled() as $row) {
            if ((int)$row["id"] === $id) {
                $jobname = $row["jobname"];
                break;
            }
        }
        if ($jobname === "") {
            $output->writeln(_("Cannot find this job ID. Use \"fwconsole job --list\" to get the job ID (not name)."));
            return false;
        }
        $output->writeln(sprintf(_("Starting phone restarts for job %s...", $jobname)));
        FreePBX::Restart()->runJobs($output, $jobname);
        $output->writeln(_("Finished"));
        return true;
    }
}
