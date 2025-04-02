<?php
namespace FreePBX\Console\Command;

use FreePBX;
use FreePBX\modules\Restart as RestartModule;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\HelpCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;

class Phonerestart extends Command
{
    private string $jobname;
    private array $extensions;

    private int $verbosity = OutputInterface::VERBOSITY_NORMAL;
    private OutputInterface $stderr;

    private InputInterface $input;
    private OutputInterface $output;

    protected function configure(): void
    {
        $this->setName("phonerestart");
        $this->setAliases(["pr"]);
        $this->setDescription(_("Restart phones"));
        $this->setDefinition(array(
            new InputOption(
                "extension", "e",
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                _("The extension to reboot (may be specified more than once)")
            ),
            new InputOption(
                "jobname", "j",
                InputOption::VALUE_REQUIRED,
                _("The name of a preset cron job to run")
            ),
            new InputOption(
                "quiet", "q",
                InputOption::VALUE_NONE,
                _("Supress error output.")
            ),
        ));
    }

    protected function initialize(InputInterface $input, OutputInterface $output): void
    {
        $this->input = $input;
        $this->output = $output;
        $this->verbosity = $output->getVerbosity();
        $this->stderr = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;

        $jobname = $input->getOption("jobname");
        $extensions = $input->getOption("extension");

        if ($jobname) {
            /** @var RestartModule $restarter */
            $restarter = FreePBX::create()->Restart;
            if (!$restarter->getConfig($jobname)) {
                $this->showHelp("Invalid job name specified");
            }
            $this->jobname = $jobname;
        }

        if ($extensions) {
            $this->extensions = $extensions;
        }

        if (empty($this->jobname) && empty($this->extensions)) {
            $this->showHelp();
        }
    }

    protected function execute(InputInterface $input, OutputInterface $output): void
    {
        /** @var RestartModule $restarter */
        $restarter = FreePBX::create()->Restart;
        if ($this->jobname) {
            if ($this->extensions) {
                $this->stderrOutput(_("Job name specified, ignoring extensions."));
            }
            $result = $restarter->runJob(
                $output,
                $this->jobname,
                delete: !str_starts_with($this->jobname, "recurring")
            );

            exit($result ? Command::SUCCESS : Command::FAILURE);
        } else {
            foreach ($this->extensions as $ext) {
                if (RestartModule::restartDevice($ext)) {
                    $output->writeln(sprintf(_("Restart request sent for %s"), $ext));
                }
            }
        }

        exit(Command::SUCCESS);
    }

    private function stderrOutput(string $msg, bool $debug = false): void
    {
        if ($this->verbosity === OutputInterface::VERBOSITY_QUIET) {
            return;
        }
        $verbosity = $debug ? OutputInterface::VERBOSITY_VERY_VERBOSE : OutputInterface::VERBOSITY_NORMAL;
        $this->stderr->writeln(trim($msg), $verbosity);
    }

    private function showHelp(string $msg = ""): void
    {
        $this->stderrOutput($msg);
        $help = new HelpCommand();
        $help->setCommand($this);
        $help->run($this->input, $this->output);
        exit(Command::INVALID);
    }
}
