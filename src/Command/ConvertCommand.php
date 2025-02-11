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
    name: 'app:rdf-to-turtle',
    description: 'Add a short description for your command',
)]
class ConvertCommand extends Command
{
    public function __construct()
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('arg1', InputArgument::OPTIONAL, 'Argument description')
            ->addOption('option1', null, InputOption::VALUE_NONE, 'Option description')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        ini_set('memory_limit', '2048M');

        $io = new SymfonyStyle($input, $output);
        $arg1 = $input->getArgument('arg1');

        //$infile = file_get_contents(__DIR__.'/../../CEDS-Ontology.rdf');

        $graph = new \EasyRdf\Graph();
        //$graph->parse($infile, 'rdfxml');
        $graph->parseFile(__DIR__.'/../../CEDS-Ontology.rdf');
        //$graph->parseFile(__DIR__.'/../../CEDS-Ontology.jsonld');
        //$graph->parseFile(__DIR__.'/../../CEDS-Ontology.ttl');

        //$output = $graph->serialise('jsonld');
        $output = $graph->serialise('ttl');
        print $output;
        exit();

        /*
        $output = $graph->serialise('jsonld');
        print $output;

        exit();
        */

        exit();

        foreach ($graph->resources() as $resource) {
            if (in_array('owl:NamedIndividual', $resource->types(), true)) {
                continue;
            }

            dump($resource->getUri());
            dump ($resource->types());
            foreach ($resource->properties() as $property) {
                if (in_array($property, ['dc11:creator'], true)) {
                    continue;
                }

                foreach ($resource->allLiterals($property) as $value) {
                    dump([$property.' value' => $value->getValue()]);
                }
                foreach ($resource->allResources($property) as $value) {
                    dump([$property.' uri' => $value->getUri()]);
                }

            }

            dump($resource->reversePropertyUris());
            //$reverseMatches = $graph->resourcesMatching('skos:inScheme', $resource->getUri());
            $reverseMatches = $graph->allOfType($resource->getUri());
            if (0 < count($reverseMatches)) {
                dump(['isOfType '.$resource->getUri() => array_map(function ($reverse) {return $reverse->label()->getValue();}, $reverseMatches)]);
            }
            /*
            foreach ($reverseMatches as $reverse) {
                dump(['isOfType '.$resource->getUri() => $reverse->label()->getValue()]);
            }
            */
        }

        //$gv = new GraphViz();
        //$gv->setUseLabels(true);
        //$gv->setOnlyLabelled(true);
        //$gv->renderImage($graph, 'svg');

        return Command::SUCCESS;
    }
}
