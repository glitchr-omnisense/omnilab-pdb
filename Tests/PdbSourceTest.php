<?php

namespace Omnilab\Pdb\Tests;

use Omnilab\Exception\NotSupportedException;
use Omnilab\Exception\ProviderException;
use Omnilab\Exception\RateLimitedException;
use Omnilab\Model\Domain;
use Omnilab\Model\Identifier;
use Omnilab\Model\Scheme;
use Omnilab\Pdb\PdbSource;
use Omnilab\Pdb\PdbSourceFactory;
use Omnilab\Source\Capability;
use Omnilab\Source\Query;
use Omnilab\Source\SourceInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Fixtures recorded from RCSB PDB's Data API (GraphQL, the holdings of
 * removed entries) and Search API on 2026-10-15, asked what the source
 * asks: the entry 4HHB (human deoxyhaemoglobin, 1984), 2FB4 (which replaced
 * 1FB4), the null GraphQL gives for the removed 1HHB and 1FB4 and their
 * holdings (1HHB replaced by three entries, 1FB4 by one); the full-text
 * search for "hemoglobin", two pages of three, and their entries.
 */
final class PdbSourceTest extends TestCase
{
    /** @var list<string> */
    private array $calls = [];

    private function source(?MockResponse $response = null, array $options = []): SourceInterface
    {
        $http = new MockHttpClient(function (string $method, string $url, array $o) use ($response): MockResponse {
            $body = json_decode((string) ($o['body'] ?? ''), true) ?? [];
            $path = (string) parse_url($url, \PHP_URL_PATH);
            $this->calls[] = match (true) {
                str_ends_with($path, '/graphql') => 'graphql '.json_encode($body['variables'] ?? []),
                str_ends_with($path, '/query') => 'search '.($body['query']['parameters']['value'] ?? '').' '.($body['request_options']['paginate']['start'] ?? '').'+'.($body['request_options']['paginate']['rows'] ?? ''),
                default => $method.' '.$path,
            };
            if ($response) {
                return $response;
            }
            $fixture = match (true) {
                str_ends_with($path, '/graphql') && isset($body['variables']['id']) => 'entry-'.strtoupper($body['variables']['id']).'.json',
                str_ends_with($path, '/graphql') => 'entries-hemoglobin-'.('6IHX' === ($body['variables']['ids'][0] ?? null) ? 3 : 0).'.json',
                str_ends_with($path, '/query') && 'hemoglobin' === ($body['query']['parameters']['value'] ?? null) => 'search-hemoglobin-'.$body['request_options']['paginate']['start'].'.json',
                str_ends_with($path, '/query') => 'no-content',
                str_starts_with($path, '/rest/v1/holdings/removed/') => 'removed-'.basename($path).'.json',
                default => null,
            };
            if ('no-content' === $fixture) {
                return new MockResponse('', ['http_code' => 204]);
            }

            return null === $fixture || !is_file(__DIR__.'/Fixtures/'.$fixture)
                ? new MockResponse('{"status":404,"message":"No data found"}', ['http_code' => 404])
                : new MockResponse((string) file_get_contents(__DIR__.'/Fixtures/'.$fixture));
        });

        // No wait between the recorded answers; the throttle's default is checked apart.
        return (new PdbSourceFactory($http))->create($options + ['throttle' => 0.0]);
    }

    public function testAnEntryInOneCall(): void
    {
        $entry = $this->source()->entity('4HHB');

        self::assertSame(['graphql {"id":"4HHB"}'], $this->calls);
        self::assertSame('pdb:4HHB', $entry->id->key());
        self::assertSame(['THE CRYSTAL STRUCTURE OF HUMAN DEOXYHAEMOGLOBIN AT 1.74 ANGSTROMS RESOLUTION', Domain::BIOLOGY, 'structure'], [$entry->label, $entry->domain, $entry->kind]);
        self::assertSame('The crystal structure of human deoxyhaemoglobin at 1.74 A resolution', $entry->description, 'its primary citation\'s title');
        self::assertSame(['OXYGEN TRANSPORT', 'X-RAY DIFFRACTION', 'heteromeric protein', 4779], [$entry->value('keywords'), $entry->value('experimental_method'), $entry->value('polymer_composition'), $entry->value('atom_count')]);
        self::assertSame(['1.74 Å', '64.74 kDa'], [(string) $entry->property('resolution'), (string) $entry->property('molecular_weight')]);
        self::assertSame(['1984-03-07', '1984-07-17', '1HHB'], [$entry->value('deposit_date'), $entry->value('release_date'), $entry->value('supersedes')]);
        self::assertSame('Fermi, G.; Perutz, M.F.', $entry->value('authors'));
        self::assertSame('Fermi, G., Perutz, M.F., Shaanan, B., Fourme, R. (1984) The crystal structure of human deoxyhaemoglobin at 1.74 A resolution. J.Mol.Biol. 175: 159', $entry->value('citation'));
        self::assertSame(['10.1016/0022-2836(84)90472-8', '6726807'], [$entry->value('doi'), $entry->value('pubmed')]);
        self::assertSame(['Hemoglobin subunit beta (chains B, D; 146 residues; polypeptide(L); Homo sapiens)', 'Hemoglobin subunit alpha (chains A, C; 141 residues; polypeptide(L); Homo sapiens)'], array_map('strval', $entry->properties('polymer_entity')));
        self::assertSame(['HEM: PROTOPORPHYRIN IX CONTAINING FE (C34 H32 Fe N4 O4)', 'PO4: PHOSPHATE ION (O4 P)'], array_map('strval', $entry->properties('ligand')));
        self::assertSame('pdb', $entry->property('resolution')->source);
        self::assertSame('https://www.rcsb.org/structure/4HHB', $entry->url);
        self::assertSame('https://cdn.rcsb.org/images/structures/hh/4hhb/4hhb_assembly-1.jpeg', $entry->image);
    }

    public function testItsProteinsOrganismsAndLigandsAreRelatedNotItsIdentifiers(): void
    {
        $entry = $this->source()->entity(Identifier::of(Scheme::PDB, '4hhb'));

        self::assertSame(['pdb:4HHB'], $entry->identifiers()->keys(), 'a structure is not its protein: nothing for the Collector to follow');
        self::assertSame(['uniprot:P68871', 'uniprot:P69905'], $entry->related('protein')->keys());
        self::assertSame(['ncbi.taxon:9606'], $entry->related('organism')->keys());
        self::assertSame(['inchikey:KABFMIBPWCXCRK-RGGAHWMASA-L', 'chebi:18367'], $entry->related('ligand')->keys(), 'heme: no ChEBI nor PubChem id at RCSB, its InChIKey; phosphate: its ChEBI id');
    }

    public function testTheSameEntryByItsExtendedIdOrItsPage(): void
    {
        foreach (['4hhb', 'pdb:4HHB', 'pdb_00004hhb', 'https://www.rcsb.org/structure/4HHB'] as $id) {
            $this->calls = [];
            self::assertSame('pdb:4HHB', $this->source()->entity($id)?->id->key(), $id);
        }
        self::assertSame(['graphql {"id":"4HHB"}'], $this->calls, 'the extended id of a four-character code asked as that code');
    }

    public function testARemovedEntryGivesItsOnlyReplacement(): void
    {
        $entry = $this->source()->entity('1FB4');

        self::assertSame(['graphql {"id":"1FB4"}', 'GET /rest/v1/holdings/removed/1FB4', 'graphql {"id":"2FB4"}'], $this->calls);
        self::assertSame('pdb:2FB4', $entry?->id->key());
        self::assertSame(['pdb:2FB4', 'pdb:1FB4'], $entry->identifiers()->keys(), 'the removed id kept, as UniProt keeps a merged accession');
        self::assertSame(['uniprot:P01772', 'uniprot:P0CG04'], $entry->related('protein')->keys());
        self::assertCount(0, $entry->related('ligand'));
    }

    public function testARemovedEntryReplacedBySeveralIsNullAsIsAnUnknownOne(): void
    {
        self::assertNull($this->source()->entity('1HHB'), '2HHB, 3HHB and 4HHB replaced it: none is it');
        self::assertNull($this->source()->entity('0XXX'), 'unknown: GraphQL\'s null, then the holdings\' 404');
    }

    public function testWhatItDoesNotReadIsRefusedBeforeAnyCall(): void
    {
        foreach ([Identifier::of(Scheme::UNIPROT, 'P68871'), Identifier::chebi('18367'), 'hemoglobin', 'Q424422', 'uniprot:P68871', '4HHBX'] as $id) {
            try {
                $this->source()->entity($id);
                self::fail('not read: '.$id);
            } catch (NotSupportedException) {
            }
        }
        self::assertSame([], $this->calls);
    }

    public function testTheFullTextSearchItsIdsThenTheirEntries(): void
    {
        $source = $this->source();
        $page = $source->search(new Query('hemoglobin', limit: 3));

        self::assertSame(['search hemoglobin 0+3', 'graphql {"ids":["2PGH","3PEL","3GOU"]}'], $this->calls);
        self::assertSame(['pdb:2PGH', 'pdb:3PEL', 'pdb:3GOU'], array_map(static fn ($e) => $e->id->key(), $page->items), 'in the search\'s order, not GraphQL\'s');
        self::assertSame([9220, '3'], [$page->total, $page->next]);
        self::assertSame('Structure of Greyhound Hemoglobin: Origin of High Oxygen Affinity', $page->items[1]->label);

        $second = $source->search(new Query('hemoglobin', limit: 3, cursor: $page->next));
        self::assertSame(['pdb:6IHX', 'pdb:20YJ', 'pdb:1G08'], array_map(static fn ($e) => $e->id->key(), $second->items));
        self::assertSame('6', $second->next);
        self::assertSame('search hemoglobin 3+3', $this->calls[2]);
    }

    public function testASearchThatFindsNothingAndOneOfAnotherDomain(): void
    {
        $page = $this->source()->search(new Query('xyzzyqwertyfoo'));
        self::assertSame([[], 0, null], [$page->items, $page->total, $page->next], 'RCSB\'s 204');
        self::assertCount(1, $this->calls, 'no GraphQL call for nothing');

        self::assertSame([], $this->source()->search(new Query('hemoglobin', Domain::CHEMISTRY))->items);
        self::assertSame([], $this->source()->search(new Query('  '))->items);
        self::assertCount(1, $this->calls);
    }

    public function testGraphQlErrorsAreProviderErrors(): void
    {
        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('GraphQL answered: Validation error');
        $this->source(new MockResponse('{"errors":[{"message":"Validation error (FieldUndefined@[entry/nope])"}]}'))->entity('4HHB');
    }

    public function testCapabilitiesAndRateLimit(): void
    {
        $source = $this->source();
        self::assertSame([Capability::ENTITY, Capability::SEARCH, Capability::STRUCTURE], $source->capabilities());
        self::assertSame(PdbSource::THROTTLE, (new \ReflectionProperty($source, 'throttle'))->getValue((new PdbSourceFactory(new MockHttpClient()))->create()), 'a handful of calls a second');

        $this->expectException(RateLimitedException::class);
        $this->source(new MockResponse('', ['http_code' => 429]))->entity('4HHB');
    }
}
