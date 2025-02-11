<?php

namespace App\Command;

use EasyRdf\Literal;
use EasyRdf\Resource;
use EasyRdf\Serialiser\GraphViz;
use League\Csv\Reader;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:Test',
    description: 'Generate SHACL document',
)]
class TestCommand extends Command
{
    public function __construct()
    {
        parent::__construct();
    }

    protected function configure(): void
    {
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        ini_set('memory_limit', '2048M');

        $graph = new \EasyRdf\Graph();
        \EasyRdf\RdfNamespace::set('ceds', 'http://ceds.ed.gov/terms#');
        \EasyRdf\RdfNamespace::set('schema', 'https://schema.org/');
        \EasyRdf\RdfNamespace::set('sh', 'http://www.w3.org/ns/shacl#');

        //$graph->parseFile(__DIR__.'/../../CEDS-Ontology.rdf');
        //$graph->parseFile(__DIR__.'/../../CEDS-Ontology.jsonld');
        $graph->parseFile(__DIR__.'/../../generated.ttl');

        $test = $graph->resource('ceds:P000826Property');
        dump($test->dump('json'));

        $output = $graph->serialise('turtle');
        print $output;


        /*
        / ** @var \EasyRdf\Collection $in * /
        $in = $test->get('sh:in');
        //dump($in);
        foreach ($in->properties() as $property) {
            foreach ($in->all($property) as $item) {
                dump($item->dump('json'));;
            }
        }
        */

        //$output = $graph->serialise('turtle');
        //print $output;

        return Command::SUCCESS;
    }
}
