<?php

namespace App\Command;

use EasyRdf\Graph;
use EasyRdf\RdfNamespace;
use EasyRdf\Resource;
use League\Csv\Reader;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:generate-shacl',
    description: 'Generate SHACL document',
)]
class GenerateShaclCommand extends Command
{
    private array $terms = [];
    private array $termxCodeSet = [];
    private array $codeSetxCode = [];
    private array $code = [];

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

        $ontGraph = new Graph();
        RdfNamespace::set('ceds', 'http://ceds.ed.gov/terms#');
        RdfNamespace::set('schema', 'https://schema.org/');
        RdfNamespace::set('sh', 'http://www.w3.org/ns/shacl#');

        //$ontGraph->parseFile(__DIR__.'/../../CEDS-Ontology.rdf');
        //$ontGraph->parseFile(__DIR__.'/../../CEDS-Ontology.jsonld');
        $ontGraph->parseFile(__DIR__.'/../../CEDS-Ontology.ttl');

        //dump(\EasyRdf\RdfNamespace::namespaces());
        $shacl = new Graph();

//@prefix rdf: <http://www.w3.org/1999/02/22-rdf-syntax-ns#> .
//@prefix rdfs: <http://www.w3.org/2000/01/rdf-schema#> .
        $generated = '@prefix sh: <http://www.w3.org/ns/shacl#> .
@prefix ceds: <http://ceds.ed.gov/terms#> .
@prefix xsd: <http://www.w3.org/2001/XMLSchema#> . 

';

        $this->setupTerms();
        $this->setupTermCodeSets();
        $this->setupCodeSetCodes();
        $this->setupCodes();

        $dataModel = $this->getPropertyList();

        $done = [];
        foreach ($dataModel as $dataModelId => $row) {
            //dump($row);
            if ($done[$row['Global ID']] ?? false) {
                // Global IDs are used multiple times, only generate the first one
                continue;
            }

            $ontProperty = $this->getOntologyProperty($ontGraph, $row['Global ID']);
            if (null === $ontProperty) {
                // Some properties are not in the ontology
                continue;
            }

            //$propertyId = 'ceds:P'.$row['Global ID'];
            $propertyId = $ontProperty->getUri();
            //$shapeId = $propertyId.'-D'.$row['Data Model ID'];

            $propertyId = $this->iriFormat(RdfNamespace::shorten($propertyId));
            $shapeId = $propertyId.'Shape';

            $generated .= $shapeId."\n";
            $shacl->addResource($shapeId, 'a', 'sh:PropertyShape');
            $generated .= "  a sh:PropertyShape ;\n";
            $shacl->addResource($shapeId, 'sh:path', $propertyId);
            $generated .= "  sh:path {$propertyId} ;\n";
            $shacl->addLiteral($shapeId, 'sh:name', $ontProperty->get('rdfs:label')->getValue());
            $generated .= "  sh:name \"".$ontProperty->get('rdfs:label')->getValue()."\" ;\n";

            /** @var Resource $range */
            $range = $ontProperty->get('schema:rangeIncludes');

            if ($ontProperty->isA('skos:ConceptScheme')) {
                $term = $this->findTerm($row['Global ID']);
                $codes = $this->findCodes($term);
                $vocab = $ontGraph->allOfType($ontProperty->getUri());

                /** @var \EasyRdf\Collection $list */
                $list = $shacl->newBNode(['rdf:List']);

                if (count($vocab) > 0) {
                    $vocab = $this->sortVocabCodes($codes, $vocab);

                    $shacl->addResource($shapeId, 'sh:datatype', 'xsd:anyURI');
                    $generated .= "  sh:datatype xsd:anyURI ;\n";

                    $generated .= "  sh:in (\n";
                    foreach ($vocab as $vocabTerm) {
                        $uri = $this->getUri($vocabTerm);
                        $list->append($vocabTerm);
                        $generated .= '    ' . $uri . '    # ' . $vocabTerm->get('skos:notation')->getValue() . ' - ' . $vocabTerm->get('rdfs:label')->getValue() . "\n";
                    }
                    $generated .= "  ) ;\n";
                } else {
                    $shacl->addResource($shapeId, 'sh:datatype', preg_replace('/^xs:/', 'xsd:', $term['xsdBaseType']));
                    $generated .= '  sh:datatype ' . preg_replace('/^xs:/', 'xsd:', $term['xsdBaseType']) . " ;\n";
                    $generated .= $this->getGeneratedFromElementTerm($shapeId, $term, $shacl);

                    $generated .= "  sh:in (\n";
                    foreach ($codes as $code) {
                        $list->append($code['Code']);
                        $generated .= '    "' . $code['Code'] . '"    # ' . $code['Code'] . ' - ' . $code['Description'] . "\n";
                    }
                    $generated .= "  ) ;\n";
                }

                $shacl->addResource($shapeId, 'sh:in', $list);
            } elseif (null !== $range && $range->isA('skos:ConceptScheme')) {
                $vocab = $ontGraph->allOfType($range->getUri());

                if (count($vocab) > 0) {
                    $shacl->addResource($shapeId, 'sh:datatype', 'xsd:anyURI');
                    $generated .= "  sh:datatype xsd:anyURI ;\n";

                    /** @var \EasyRdf\Collection $list */
                    $list = $shacl->newBNode(['rdf:List']);
                    $generated .= "  sh:in (\n";
                    /** @var Resource $vocabTerm */
                    foreach ($vocab as $vocabTerm) {
                        $uri = $this->getUri($vocabTerm);
                        $list->append($vocabTerm);
                        $generated .= "    " . $uri . '    # ' . $vocabTerm->get('skos:notation')->getValue() . ' - ' . $vocabTerm->get('rdfs:label')->getValue() . "\n";
                    }
                    $generated .= "  ) ;\n";
                    $shacl->addResource($shapeId, 'sh:in', $list);
                } else {
                    dump('no vocab', $range->getUri());
                }
            } elseif ($range instanceof Resource) {
                //$generated .= "R is R\n";
                $term = $this->findTerm($row['Global ID']);
                $baseType = $term['xsdBaseType'];
                if ($term['xsdBaseType'] === '') {
                    $baseType = $this->iriFormat(RdfNamespace::shorten($range->getUri()));
                }
                $shacl->addResource($shapeId, 'sh:datatype', preg_replace('/^xs:/', 'xsd:', $baseType));
                $generated .= "  sh:datatype ".preg_replace('/^xs:/', 'xsd:', $baseType)." ;\n";
                $generated .= $this->getGeneratedFromElementTerm($shapeId, $term, $shacl);
            } else {
                dump('edge case');
                if ($shapeId === '') {
                    dump('empty id', $row);
                    $generated .= ".\n";
                    continue;
                }
                $term = $this->findTerm($row['Global ID']);
                if ($term['xsdBaseType'] === '') {
                    dump('empty term', $term, $shapeId, $range?->dump('jsonld'));
                    $rangeIncludes = $ontProperty->get('schema:rangeIncludes')?->getValue() ?? '';
                    if (1 !== preg_match('/^xsd?:/', $rangeIncludes)) {
                        $generated .= $ontProperty->dump('jsonld');
                        $generated .= $ontProperty->get('schema:rangeIncludes')?->dump('jsonld');
                        $generated .= ".\n";
                        continue;
                    }

                    $term['xsdBaseType'] = $rangeIncludes;
                }
                $shacl->addResource($shapeId, 'sh:datatype', preg_replace('/^xs:/', 'xsd:', $term['xsdBaseType']));
                $generated .= '  sh:datatype ' . preg_replace('/^xs:/', 'xsd:', $term['xsdBaseType']) . " ;\n";
                $generated .= $this->getGeneratedFromElementTerm($shapeId, $term, $shacl);
            }

            $generated .= ".\n\n";

            $done[$row['Global ID']] = true;
        }

        file_put_contents('output-with-comments.ttl', $generated);
        file_put_contents('output.ttl', $shacl->serialise('ttl'));

        return Command::SUCCESS;
    }

    protected function findTerm(string $globalId): ?array
    {
        $ret = array_filter($this->terms, function ($term) use ($globalId) {
            return ($term['GlobalID'] === $globalId);
        });

        if (count($ret) === 0) {
            return null;
        }

        usort($ret, function ($a, $b) {
            return strnatcmp($a['Version'], $b['Version'])*-1;
        });

        return $ret[0];
    }

    protected function findCodes(array $term): ?array {
        $globalId = $term['GlobalID'];
        $termRec = $this->findTerm($globalId);

        $codeSet = $this->termxCodeSet[$termRec['TermID']];
        if (!$codeSet) {
            return null;
        }

        $codeList = $this->codeSetxCode[$codeSet['CodeSetID']];
        uasort($codeList, function ($a, $b) {
            return $a['SortOrder'] <=> $b['SortOrder'];
        });

        $codes = [];
        foreach ($codeList as $codeId => $codeVal) {
            $codes[] = $this->code[$codeId];
        }

        return $codes;
    }

    protected function getGeneratedFromElementTerm(string $shapeId, ?array $term, Graph $shacl): string
    {
        $generated = '';

        if ($term['minLength']) {
            $shacl->addLiteral($shapeId, 'sh:minLength', (int)$term['minLength']);
            $generated .= "  sh:minLength {$term['minLength']} ; \n";
        }
        if ($term['maxLength']) {
            $shacl->addLiteral($shapeId, 'sh:maxLength', (int)$term['maxLength']);
            $generated .= "  sh:maxLength {$term['maxLength']} ; \n";
        }
        if ($term['minInclusive'] !== '') {
            $shacl->addLiteral($shapeId, 'sh:minInclusive', (int)$term['minInclusive']);
            $generated .= "  sh:minInclusive \"{$term['minInclusive']}\"^^xsd:decimal ; \n";
        }
        if ($term['maxInclusive']) {
            $shacl->addLiteral($shapeId, 'sh:maxInclusive', (int)$term['maxInclusive']);
            $generated .= "  sh:maxInclusive \"{$term['maxInclusive']}\"^^xsd:decimal ; \n";
        }
        if ($term['fractionDigits']) {
            $shacl->addLiteral($shapeId, 'sh:pattern', '^\\d+(\\.\\d{0,' . $term['fractionDigits'] . '})?$');
            $generated .= '  sh:pattern "^\\\\d+(\\\\.\\\\d{0,' . $term['fractionDigits'] . '})?$" ;' . "\n";
        }
        if ($term['minOccurs']) {
            $shacl->addLiteral($shapeId, 'sh:minCount', (int)$term['minOccurs']);
            $generated .= "  sh:minCount {$term['minOccurs']} ; \n";
        }
        if ($term['maxOccurs']) {
            $shacl->addLiteral($shapeId, 'sh:maxCount', (int)$term['maxOccurs']);
            $generated .= "  sh:maxCount {$term['maxOccurs']} ; \n";
        }

        return $generated;
    }

    private function getPropertyList(): array
    {
        $dataModel = [];
        $properties = Reader::createFromPath(__DIR__ . '/../../db-extract/Properties.tsv');
        $properties->setDelimiter("\t");
        $properties->setHeaderOffset(0);
        foreach ($properties as $row) {
            $dataModel[$row['Data Model ID']] = $row;
        }

        return $dataModel;
    }

    private function getOntologyProperty(Graph $ontGraph, string $globalID): ?Resource
    {
        $ontProperty = $ontGraph->resourcesMatching('dc11:identifier', 'P' . $globalID);
        if (count($ontProperty) === 0) {
            //var_dump($row);
            $ontProperty = $ontGraph->resourcesMatching('dc11:identifier', 'C' . $globalID);

            if (count($ontProperty) === 0) {
                //dump('CANT FIND', $row);
                return null;
            }
        }

        return $ontProperty[0];
    }

    private function sortVocabCodes(array $codes, array $vocab): array
    {
        $codeList = [];
        foreach ($codes as $key => $code) {
            $codeList[$code['Code']] = $key;
        }
        usort($vocab, function ($a, $b) use ($codeList) {
            return $codeList[$a->get('skos:notation')->getValue()] <=> $codeList[$b->get('skos:notation')->getValue()];
        });

        return $vocab;
    }

    private function getUri(Resource $term): string
    {
        $uri = $term->getUri();
        $shortUri = RdfNamespace::shorten($uri);
        if (null === $shortUri || '' === $shortUri) {
            //dump('short term not found', $term->dump('jsonld'));
            if (str_starts_with($uri, RdfNamespace::get('ceds'))) {
                $shortUri = str_replace(RdfNamespace::get('ceds'), 'ceds:', $uri);
                $uri = $this->iriFormat($shortUri);
            } else {
                $uri = '<' . $uri . '>';
            }
        } else {
            $uri = $this->iriFormat($shortUri);
        }

        return $uri;
    }

    private function setupCodes(): void
    {
        $reader = Reader::createFromPath(__DIR__ . '/../../db-extract/Code.csv');
        $reader->setHeaderOffset(0);
        foreach ($reader as $row) {
            $this->code[$row['CodeID']] = $row;
        }
    }

    private function setupCodeSetCodes(): void
    {
        $reader = Reader::createFromPath(__DIR__ . '/../../db-extract/CodeSetxCode.csv');
        $reader->setHeaderOffset(0);
        foreach ($reader as $row) {
            $this->codeSetxCode[$row['CodeSetID']][$row['CodeID']] = $row;
        }
    }

    private function setupTerms(): void
    {
        $reader = Reader::createFromPath(__DIR__ . '/../../db-extract/Term.csv');
        $reader->setHeaderOffset(0);
        foreach ($reader as $row) {
            $this->terms[$row['TermID']] = $row;
        }
    }

    private function setupTermCodeSets(): void
    {
        $reader = Reader::createFromPath(__DIR__ . '/../../db-extract/TermxCodeSet.csv');
        $reader->setHeaderOffset(0);
        foreach ($reader as $row) {
            $this->termxCodeSet[$row['TermID']] = $row;
        }
    }

    private function iriFormat(?string $shorten): ?string
    {
        if (null === $shorten) {
            return null;
        }

        $ret = preg_replace('/\.$/', '\\.', $shorten); # %2E
        $ret = preg_replace('/\*/', '\\*', $ret); # %2A
        $ret = preg_replace('#/#', '\\/', $ret); # %2F
        $ret = preg_replace('/ /', '%20', $ret); # %20
        $ret = preg_replace('/—/', '%E2%80%94', $ret); # %E2 %80 %94 \u2014
        //$ret = urlencode($shorten);
        //$ret = preg_replace('/^ceds%3A/', 'ceds:', $ret);

        return $ret;
    }
}
