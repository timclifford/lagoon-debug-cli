<?php

namespace App\Commands;

use App\Domain\DomainDetails;
use Clockwork\Support\Vanilla\Clockwork;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Input\InputArgument;

class GetDomainDetails extends Command {

    private array $projects = [];

    protected function configure() {
        $this
            ->setName('domain:details')
            ->setDescription('Get domain details')
            ->addArgument('domain', InputArgument::OPTIONAL, 'Domain to check - e.g. admin.fastly.amazeeio.cloud')
            ->setHelp(
                <<<EOT
        Testing domain details from debug tool.
        EOT
            )
            ->setAliases(['sh']);
    }

    /**
     * {@inheritdoc}
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Google_Exception
     */
    protected function execute(InputInterface $input, OutputInterface $output) {
        $io = new SymfonyStyle($input, $output);
//        $io->title('Domain details');
        $combinedJson = [];

        $domain = $input->getArgument('domain');
        if (!$domain) {
            $io->writeln("No domain given");
            throw new \Exception("No domain given.");
        }

        $clockwork = Clockwork::init();
        $domainDetails = new DomainDetails($clockwork, $domain);
        $payload = json_encode($domainDetails->getVariables());

        $io->writeln($payload);

        return 0;
    }
}