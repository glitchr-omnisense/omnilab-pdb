<?php

namespace Omnilab\Pdb;

use Omnilab\Exception\NotSupportedException;
use Omnilab\Exception\ProviderException;
use Omnilab\Model\Domain;
use Omnilab\Model\Entity;
use Omnilab\Model\Identifier;
use Omnilab\Model\Identifiers;
use Omnilab\Model\Property;
use Omnilab\Model\Quantity;
use Omnilab\Model\Scheme;
use Omnilab\Source\Capability;
use Omnilab\Source\HttpSource;
use Omnilab\Source\Page;
use Omnilab\Source\Query;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The Protein Data Bank, through the APIs of RCSB PDB: an entry by its PDB
 * id (4HHB, or its extended form pdb_00004hhb) - its title, keywords,
 * experimental method, resolution, weight, dates, primary citation and
 * authors; its polymer entities (the chains: their description, strands,
 * length, organism) and its ligands (their chemical component, formula,
 * weight) as properties; the UniProt proteins of its chains, their
 * organisms and its ligands as related things - a structure is not its
 * protein, nor its ligand: the Collector follows none of them -; an image of
 * its first assembly; and RCSB's full-text search.
 *
 * Which API: the Data API's GraphQL endpoint (data.rcsb.org/graphql), one
 * call for an entry with its entities, where the REST API takes one for the
 * entry, one per polymer entity and one per ligand; RCSB's Search API
 * (search.rcsb.org) for the search, which answers ids only, then one GraphQL
 * call for the page's entries; the Data API's holdings for an entry removed
 * from the archive (its replacement, when it has one).
 *
 * No key. RCSB rate-limits every API, answers 429 beyond, and asks a
 * client to start at a handful of calls a second: 0.25 s between two.
 */
final class PdbSource extends HttpSource
{
    public const BASE_URI = 'https://data.rcsb.org/';

    public const SEARCH_URI = 'https://search.rcsb.org/rcsbsearch/v2/query';

    /** Seconds between two calls: RCSB's "a handful a second". */
    public const THROTTLE = 0.25;

    /** The most entries one search page is asked for: their GraphQL answer grows with each. */
    public const MAX_PER_PAGE = 100;

    /** What GraphQL is asked of an entry. */
    private const FIELDS = <<<'GRAPHQL'
        fragment Entry on CoreEntry {
          rcsb_id
          struct { title }
          struct_keywords { pdbx_keywords text }
          exptl { method }
          rcsb_entry_info { resolution_combined experimental_method molecular_weight deposited_atom_count polymer_entity_count nonpolymer_entity_count polymer_composition }
          rcsb_accession_info { deposit_date initial_release_date revision_date status_code }
          rcsb_primary_citation { title journal_abbrev journal_volume page_first year pdbx_database_id_DOI pdbx_database_id_PubMed rcsb_authors }
          audit_author { name }
          rcsb_entry_container_identifiers { assembly_ids emdb_ids }
          pdbx_database_PDB_obs_spr { replace_pdb_id }
          polymer_entities {
            rcsb_id
            rcsb_polymer_entity { pdbx_description formula_weight pdbx_number_of_molecules }
            entity_poly { type pdbx_strand_id rcsb_sample_sequence_length }
            rcsb_entity_source_organism { ncbi_taxonomy_id ncbi_scientific_name }
            rcsb_polymer_entity_container_identifiers { uniprot_ids }
          }
          nonpolymer_entities {
            rcsb_id
            rcsb_nonpolymer_entity { pdbx_number_of_molecules }
            nonpolymer_comp {
              chem_comp { id name formula formula_weight }
              rcsb_chem_comp_descriptor { InChIKey }
              rcsb_chem_comp_related { resource_name resource_accession_code }
            }
          }
        }
        GRAPHQL;

    public function __construct(
        HttpClientInterface $http,
        private readonly string $searchUri = self::SEARCH_URI,
        string $baseUri = self::BASE_URI,
        array $headers = [],
        float $throttle = self::THROTTLE,
    ) {
        parent::__construct($http, $baseUri, $headers, $throttle);
    }

    public function getName(): string
    {
        return 'pdb';
    }

    public function capabilities(): array
    {
        return [Capability::ENTITY, Capability::SEARCH, Capability::STRUCTURE];
    }

    /**
     * An entry by its PDB id (4HHB, 4hhb, pdb:4HHB, pdb_00004hhb, its RCSB
     * page): one call. An entry removed from the archive gives the one that
     * replaced it, when only one did (two calls more); several, or none:
     * null. A UniProt accession, a name or any other scheme is refused
     * before any call: search() looks entries up by text.
     */
    public function entity(Identifier|string $id): ?Entity
    {
        if ($id instanceof Identifier) {
            $identifier = Scheme::PDB === $id->scheme ? $id : throw NotSupportedException::identifier('pdb', (string) $id);
        } else {
            // A bare code first: this source reads nothing else.
            $identifier = Identifier::tryOf(Scheme::PDB, trim($id)) ?? Identifier::parse(trim($id));
            if (null === $identifier || Scheme::PDB !== $identifier->scheme) {
                throw NotSupportedException::identifier('pdb', $id);
            }
        }
        // An extended id of a four-character code is that code: pdb_00004hhb is 4HHB.
        $code = preg_match('~^pdb_0000([0-9a-z]{4})$~', $identifier->value, $m) ? strtoupper($m[1]) : $identifier->value;
        $entry = $this->graphql('query($id: String!) { entry(entry_id: $id) { ...Entry } }', ['id' => $code])['entry'] ?? null;
        if (\is_array($entry)) {
            return $this->toEntity($entry);
        }
        // Removed from the archive: its replacement, when there is one.
        $removed = $this->getJson('rest/v1/holdings/removed/'.rawurlencode(strtoupper($code)));
        $by = array_values(array_filter((array) ($removed['rcsb_repository_holdings_removed']['id_codes_replaced_by'] ?? []), 'is_string'));
        if (1 !== \count($by)) {
            return null;
        }
        $entry = $this->graphql('query($id: String!) { entry(entry_id: $id) { ...Entry } }', ['id' => $by[0]])['entry'] ?? null;

        return \is_array($entry) ? $this->toEntity($entry, Identifier::tryOf(Scheme::PDB, $code)) : null;
    }

    /**
     * RCSB's full-text search over the experimental structures ("hemoglobin",
     * "spike glycoprotein SARS-CoV-2"), the best first, page by offset: the
     * Search API's ids, then their entries in one GraphQL call. A domain
     * other than biology answers nothing.
     */
    public function search(Query $query): Page
    {
        $text = trim($query->text);
        if ((null !== $query->domain && Domain::BIOLOGY !== $query->domain) || '' === $text) {
            return new Page();
        }
        $offset = max(0, (int) $query->cursor);
        $rows = max(1, min(self::MAX_PER_PAGE, $query->limit));
        $found = $this->postForJson($this->searchUri, [
            'query' => ['type' => 'terminal', 'service' => 'full_text', 'parameters' => ['value' => $text]],
            'return_type' => 'entry',
            'request_options' => [
                'paginate' => ['start' => $offset, 'rows' => $rows],
                // The archive's structures, not the computed models RCSB also serves (AlphaFold's, whose ids are no PDB ids).
                'results_content_type' => ['experimental'],
                'sort' => [['sort_by' => 'score', 'direction' => 'desc']],
            ],
        ]);
        // 204: nothing matched.
        if (null === $found) {
            return new Page([], null, 0);
        }
        $ids = [];
        foreach ($found['result_set'] ?? [] as $result) {
            if (null !== ($id = Identifier::tryOf(Scheme::PDB, $result['identifier'] ?? null))) {
                $ids[] = $id->value;
            }
        }
        $total = isset($found['total_count']) ? (int) $found['total_count'] : null;
        $entities = [];
        if ($ids) {
            $byId = [];
            foreach ($this->graphql('query($ids: [String!]!) { entries(entry_ids: $ids) { ...Entry } }', ['ids' => $ids])['entries'] ?? [] as $entry) {
                if (\is_array($entry) && isset($entry['rcsb_id'])) {
                    $byId[strtoupper((string) $entry['rcsb_id'])] = $entry;
                }
            }
            foreach ($ids as $id) {
                if (isset($byId[strtoupper($id)])) {
                    $entities[] = $this->toEntity($byId[strtoupper($id)]);
                }
            }
        }
        $next = $offset + \count($found['result_set'] ?? []);

        return new Page($entities, null !== $total && $next < $total && $ids ? (string) $next : null, $total);
    }

    /**
     * A GraphQL query of the Data API, the entry's fields as a fragment; its
     * data, or a ProviderException for the errors GraphQL answers with a 200.
     *
     * @param array<string, mixed> $variables
     *
     * @return array<string, mixed>
     */
    private function graphql(string $query, array $variables): array
    {
        $answer = $this->postForJson('graphql', ['query' => $query."\n".self::FIELDS, 'variables' => $variables]) ?? [];
        if (!empty($answer['errors'])) {
            throw new ProviderException('pdb', 'GraphQL answered: '.implode('; ', array_map(static fn (mixed $e) => \is_array($e) ? (string) ($e['message'] ?? '?') : (string) $e, (array) $answer['errors'])));
        }

        return \is_array($answer['data'] ?? null) ? $answer['data'] : [];
    }

    /** @param array<string, mixed> $e an entry as GraphQL gives it */
    private function toEntity(array $e, ?Identifier $removed = null): Entity
    {
        $id = strtoupper((string) $e['rcsb_id']);
        $info = $e['rcsb_entry_info'] ?? [];
        $dates = $e['rcsb_accession_info'] ?? [];
        $citation = $e['rcsb_primary_citation'] ?? [];
        $title = self::text($e['struct']['title'] ?? null);

        $properties = [];
        $add = static function (string $key, string $label, Quantity|string|int|float|null $value) use (&$properties): void {
            if (null !== $value && '' !== $value) {
                $properties[] = new Property($key, $label, $value, 'pdb');
            }
        };
        $add('title', 'Title', $title);
        $add('keywords', 'Keywords', self::text($e['struct_keywords']['pdbx_keywords'] ?? null));
        foreach ($e['exptl'] ?? [] as $experiment) {
            $add('experimental_method', 'Experimental method', self::text($experiment['method'] ?? null));
        }
        $resolution = $info['resolution_combined'][0] ?? null;
        $add('resolution', 'Resolution', is_numeric($resolution) ? new Quantity((float) $resolution, 'Å') : null);
        $add('molecular_weight', 'Molecular weight (of the deposited model)', is_numeric($info['molecular_weight'] ?? null) ? new Quantity((float) $info['molecular_weight'], 'kDa') : null);
        $add('atom_count', 'Atoms (deposited)', is_numeric($info['deposited_atom_count'] ?? null) ? (int) $info['deposited_atom_count'] : null);
        $add('polymer_composition', 'Polymer composition', self::text($info['polymer_composition'] ?? null));
        $add('deposit_date', 'Deposited', self::day($dates['deposit_date'] ?? null));
        $add('release_date', 'Released', self::day($dates['initial_release_date'] ?? null));
        $add('revision_date', 'Last revised', self::day($dates['revision_date'] ?? null));
        $authors = array_values(array_filter(array_map(static fn (mixed $a) => \is_array($a) ? self::text($a['name'] ?? null) : null, $e['audit_author'] ?? [])));
        $add('authors', 'Authors (of the entry)', $authors ? implode('; ', $authors) : null);
        $add('citation', 'Primary citation', self::citation($citation));
        $add('doi', 'DOI (of the primary citation)', self::text($citation['pdbx_database_id_DOI'] ?? null));
        $add('pubmed', 'PubMed (of the primary citation)', is_numeric($citation['pdbx_database_id_PubMed'] ?? null) ? (string) $citation['pdbx_database_id_PubMed'] : null);
        foreach ($e['rcsb_entry_container_identifiers']['emdb_ids'] ?? [] as $map) {
            $add('emdb', 'EMDB map', self::text($map));
        }
        foreach ($e['pdbx_database_PDB_obs_spr'] ?? [] as $superseded) {
            $add('supersedes', 'Supersedes (an entry removed)', self::text($superseded['replace_pdb_id'] ?? null));
        }

        $proteins = $organisms = $ligands = [];
        foreach ($e['polymer_entities'] ?? [] as $polymer) {
            $entity = $polymer['rcsb_polymer_entity'] ?? [];
            $poly = $polymer['entity_poly'] ?? [];
            $sources = [];
            foreach ($polymer['rcsb_entity_source_organism'] ?? [] as $organism) {
                $organisms[] = Identifier::tryOf(Scheme::NCBI_TAXON, $organism['ncbi_taxonomy_id'] ?? null);
                $sources[] = self::text($organism['ncbi_scientific_name'] ?? null);
            }
            foreach ($polymer['rcsb_polymer_entity_container_identifiers']['uniprot_ids'] ?? [] as $accession) {
                $proteins[] = Identifier::tryOf(Scheme::UNIPROT, $accession);
            }
            $details = array_filter([
                isset($poly['pdbx_strand_id']) ? 'chains '.str_replace(',', ', ', (string) $poly['pdbx_strand_id']) : null,
                is_numeric($poly['rcsb_sample_sequence_length'] ?? null) ? $poly['rcsb_sample_sequence_length'].' residues' : null,
                self::text($poly['type'] ?? null),
                implode(', ', array_unique(array_filter($sources))) ?: null,
            ]);
            $add('polymer_entity', 'Polymer entity', self::text($entity['pdbx_description'] ?? null).($details ? ' ('.implode('; ', $details).')' : ''));
        }
        foreach ($e['nonpolymer_entities'] ?? [] as $nonpolymer) {
            $component = $nonpolymer['nonpolymer_comp'] ?? [];
            $chem = $component['chem_comp'] ?? [];
            $related = [];
            foreach ($component['rcsb_chem_comp_related'] ?? [] as $link) {
                $related[(string) ($link['resource_name'] ?? '')] ??= (string) ($link['resource_accession_code'] ?? '');
            }
            // One identifier a ligand: its ChEBI id (what UniProt's ligands are), else its PubChem CID, else its InChIKey.
            $ligands[] = Identifier::tryOf(Scheme::CHEBI, $related['ChEBI'] ?? null)
                ?? Identifier::tryOf(Scheme::PUBCHEM_CID, $related['PubChem'] ?? null)
                ?? Identifier::tryOf(Scheme::INCHIKEY, $component['rcsb_chem_comp_descriptor']['InChIKey'] ?? null);
            $code = self::text($chem['id'] ?? null);
            $formula = self::text($chem['formula'] ?? null);
            $add('ligand', 'Ligand (chemical component)', null === $code ? null : $code.(null !== ($name = self::text($chem['name'] ?? null)) ? ': '.$name : '').(null !== $formula ? ' ('.$formula.')' : ''));
        }

        $lower = strtolower($id);
        $assembly = ($e['rcsb_entry_container_identifiers']['assembly_ids'] ?? [])[0] ?? null;

        return new Entity(
            id: Identifier::of(Scheme::PDB, $id),
            label: $title ?? $id,
            domain: Domain::BIOLOGY,
            kind: 'structure',
            description: self::text($citation['title'] ?? null),
            properties: $properties,
            identifiers: Identifiers::of($removed),
            url: 'https://www.rcsb.org/structure/'.$id,
            // RCSB's picture of its first biological assembly, where a 4-character code keeps it.
            image: null !== $assembly && 4 === \strlen($lower) ? \sprintf('https://cdn.rcsb.org/images/structures/%s/%s/%s_assembly-%s.jpeg', substr($lower, 1, 2), $lower, $lower, $assembly) : null,
            source: 'pdb',
            related: array_filter([
                'protein' => new Identifiers($proteins),
                'organism' => new Identifiers($organisms),
                'ligand' => new Identifiers($ligands),
            ], static fn (Identifiers $ids) => \count($ids) > 0),
        );
    }

    /** "Fermi, G., Perutz, M.F., Shaanan, B., Fourme, R. (1984) The crystal structure... J.Mol.Biol. 175: 159" */
    private static function citation(array $c): ?string
    {
        $title = self::text($c['title'] ?? null);
        if (null === $title) {
            return null;
        }
        $authors = array_values(array_filter(array_map(static fn (mixed $a) => \is_string($a) ? self::text($a) : null, $c['rcsb_authors'] ?? [])));
        $journal = trim(implode(' ', array_filter([self::text($c['journal_abbrev'] ?? null), self::text(isset($c['journal_volume']) ? (string) $c['journal_volume'] : null)])).(isset($c['page_first']) ? ': '.$c['page_first'] : ''));

        return trim(($authors ? implode(', ', $authors).' ' : '').(isset($c['year']) ? '('.$c['year'].') ' : '').rtrim($title, '.').'.'.('' !== $journal ? ' '.$journal : ''));
    }

    /** The day of an RCSB date ("1984-03-07T00:00:00Z"). */
    private static function day(mixed $date): ?string
    {
        return \is_string($date) && preg_match('~^(\d{4}-\d{2}-\d{2})~', $date, $m) ? $m[1] : null;
    }
}
