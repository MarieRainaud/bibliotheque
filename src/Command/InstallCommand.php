<?php
namespace App\Command;
use App\Service\Database;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
#[AsCommand(name:'app:install',description:'Initialiser les tables de la bibliothèque sans effacer les données.')]
final class InstallCommand extends Command
{
    public function __construct(private Database $database) { parent::__construct(); }
    protected function execute(InputInterface $input,OutputInterface $output): int { $this->database->install();$output->writeln('Bibliothèque initialisée.');return Command::SUCCESS; }
}
