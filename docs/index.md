# omnilab/pdb

The Protein Data Bank through RCSB PDB's GraphQL Data API (`https://data.rcsb.org/`) and Search API
(`https://search.rcsb.org/rcsbsearch/v2/query`) for [omnisense/omnilab](https://github.com/glitchr-omnisense/omnilab):
structures by PDB id - method, resolution, citation, chains and ligands - with their UniProt
proteins, organisms and ligands as related things, as entities; a full-text search. No key.

## Installation

```sh
composer require omnilab/pdb
```

```php
use Omnilab\Pdb\PdbSourceFactory;

$pdb = (new PdbSourceFactory($httpClient))->create(['mailto' => 'support@glitchr.io']);
```

With the Symfony bundle, the factory is registered as `pdb` (see the core's docs).

## Credentials

None: RCSB PDB's Data and Search APIs take no key and no account. Give `mailto`, the address RCSB
writes to before it blocks a caller.

## Options

The factory's options, with the defaults it applies (`PdbSourceFactory`):

| Option | Type | Default | Required | Effect |
|---|---|---|---|---|
| `search_uri` | `string` | `https://search.rcsb.org/rcsbsearch/v2/query` | no | the Search API (`rcsbsearch/v2/query`) |
| `mailto` | `?string` | null | no | a contact address, in the User-Agent: whom RCSB writes to before it blocks a caller |
| `user_agent` | `?string` | null | no | the whole User-Agent, in place of the one made from `mailto` |
| `throttle` | `float` | `0.25` | no | seconds left between two calls: RCSB asks to start at a handful a second, and answers 429 beyond its limit |
| `base_uri` | `string` | `https://data.rcsb.org/` | no | the Data API: GraphQL (`graphql`) and the holdings of removed entries (`rest/v1/holdings/removed/...`) |

## Capabilities

`capabilities()` answers `entity`, `search`, `structure`; an operation it does not answer throws `NotSupportedException` before any call.

| Capability | Supported | |
|---|---|---|
| `entity` | yes | a PDB id (`4HHB`, `pdb:`, the extended `pdb_00004hhb`, its RCSB page): one GraphQL call; a removed entry gives the one that replaced it, when only one did; another scheme refused before any call |
| `search` | yes | RCSB's full-text search over the experimental structures, the best first, 100 a page at most, with a `total`; then one GraphQL call for the page's entries; a domain other than biology answers an empty page |
| `series` | no |  |
| `products` | no |  |
| `occurrences` | no |  |
| `datasets` | no |  |
| `jobs` | no |  |
| `open` | no |  |
| `structure` | yes | the image is RCSB's picture of its first biological assembly |
| `cross_references` | no | none: a structure is not its protein; its chains' UniProt proteins, their organisms and its ligands are `related` |
| `forecast` | no |  |
| `historical` | no |  |
| `area` | no |  |
| `station` | no |  |
| `cloud_cover` | no |  |

## Which API, and why

| | GraphQL (chosen) | REST |
|---|---|---|
| an entry with its entities | one call, the fields asked only (3 kB for 4HHB) | `core/entry/4HHB` (the entry, 26 kB), then `core/polymer_entity/4HHB/1`, `/2`, `core/nonpolymer_entity/4HHB/3`, `/4`: five calls |
| a page of search results | one call for all the page's entries (`entries(entry_ids: [...])`) | one to five calls an entry |

The search is RCSB's Search API, which answers ids and scores only (`return_type: entry`); the
source asks it for the experimental structures alone (`results_content_type: experimental`: the
computed models RCSB serves beside them, AlphaFold's, have no PDB id), the best first, then asks
GraphQL for the page's entries and keeps the search's order (GraphQL answers in its own). A search
that matches nothing is a 204, read as an empty page. GraphQL's errors come with a 200: the source
throws a `ProviderException` with their messages.

## Calls

| Method | Reads | RCSB |
|---|---|---|
| `entity()` | a PDB id: `4HHB`, `4hhb`, `pdb:4HHB`, the extended `pdb_00004hhb`, `https://www.rcsb.org/structure/4HHB` | GraphQL `entry(entry_id:)` |
| | a removed entry (`1FB4`): the one that replaced it, when only one did | then `rest/v1/holdings/removed/1FB4`, then GraphQL for `2FB4` |
| `search()` | `Query::$text`, full text; a domain other than biology answers nothing | Search API, then GraphQL `entries()` |

An extended id of a four-character code is asked as that code (`pdb_00004hhb` is `4HHB`). A removed
entry replaced by several (`1HHB`: `2HHB`, `3HHB`, `4HHB`) is null - none of them is it -, as is an
unknown id. A UniProt accession, a name, any other scheme is refused before any call.

## What an entity holds

`id` (`pdb:4HHB`), `label` (its title, as deposited: old entries are upper case), `domain` biology,
`kind` `structure`, `description` (its primary citation's title), `url` (its RCSB page), `image` (RCSB's
picture of its first biological assembly, `cdn.rcsb.org/images/structures/{2 middle characters}/{id}/{id}_assembly-1.jpeg`),
and:

| Key | RCSB | Value |
|---|---|---|
| `title`, `keywords` | `struct.title`, `struct_keywords.pdbx_keywords` | text |
| `experimental_method` | each `exptl.method`: `X-RAY DIFFRACTION`, `ELECTRON MICROSCOPY`, `SOLUTION NMR`... | text |
| `resolution` | `rcsb_entry_info.resolution_combined` | Quantity, Å |
| `molecular_weight` | `rcsb_entry_info.molecular_weight`, the deposited model's | Quantity, kDa |
| `atom_count`, `polymer_composition` | deposited atoms; `heteromeric protein`, `protein/NA`... | number, text |
| `deposit_date`, `release_date`, `revision_date` | `rcsb_accession_info` | text, a day |
| `authors` | the entry's `audit_author` | text |
| `citation`, `doi`, `pubmed` | the primary citation: its authors, year, title, journal, volume, first page; its DOI and PubMed id | text |
| `emdb` | the electron microscopy maps it was built in | text |
| `supersedes` | the removed entries it replaced (`1HHB`) | text |
| `polymer_entity` | each chain's description, its strands, length, type and organism: `Hemoglobin subunit beta (chains B, D; 146 residues; polypeptide(L); Homo sapiens)` | text |
| `ligand` | each chemical component: `HEM: PROTOPORPHYRIN IX CONTAINING FE (C34 H32 Fe N4 O4)` | text |

`identifiers`: none but its own, and the removed id it was asked by. `related` - other things, the
`Collector` does not follow them, the `Merger` joins nothing by them ([the core's
decision](https://github.com/glitchr-omnisense/omnilab/blob/1.x/docs/decisions.md)):

- `protein`: the UniProt accessions of its chains;
- `organism`: their NCBI taxa;
- `ligand`: one identifier a ligand - its ChEBI id when RCSB gives one (what UniProt's ligands
  are), else its PubChem CID, else its InChIKey (RCSB gives heme neither: its InChIKey).

## The Collector

A structure is not its protein: a PDB entry carries no identifier the other sources share, so the
`Collector` takes it alone, and it is reached through the related things of the others. Answered on
2026-10-15 (the core's `docs/merging.md` gives it whole):

1. `$uniprot->entity('P68871')->related('structure')`: hemoglobin beta's structures, 20 kept by
   omnilab/uniprot (`1A00`, `1A01`, `1A0U`... in UniProt's order);
2. `$collector->collect('pdb', '4HHB')`: one call; `sources` is `pdb` alone, nothing to follow;
3. back: `$collector->collect('uniprot', $entry->related('protein')->get(Scheme::UNIPROT))` reads its
   chains' proteins (P69905 alpha, P68871 beta), `$chebi->entity('chebi:18367')` its phosphate
   (`phosphate(3−)`). PubChem knows no compound under the InChIKey RCSB gives heme
   (`KABFMIBPWCXCRK-RGGAHWMASA-L`): null.

## What RCSB does not give here

- No Wikidata link: Wikidata's P638 lists the structures of a compound or a protein, which are not
  the thing itself (omnilab/wikidata reads it as nothing).
- Not read: the coordinates and the experimental data (files to download: `files.rcsb.org`), the
  assemblies' details, the validation reports, the branched entities (glycans), the sequence and
  structure searches of the Search API, the computed models (AlphaFold DB's, through RCSB).

## Example

```php
<?php

require __DIR__.'/vendor/autoload.php';

use Omnilab\Model\Scheme;
use Omnilab\Pdb\PdbSourceFactory;
use Omnilab\Source\Query;
use Symfony\Component\HttpClient\HttpClient;

$pdb = (new PdbSourceFactory(HttpClient::create()))->create(['mailto' => 'you@example.org']);

$entry = $pdb->entity('4HHB');
echo $entry->id, ' ', $entry->label, "\n";
echo '  ', $entry->value('experimental_method'), ', ', $entry->property('resolution'), ', ', $entry->property('molecular_weight'), ', proteins ', implode(', ', $entry->related('protein')->values(Scheme::UNIPROT)), "\n";
foreach ($entry->properties('ligand') as $ligand) {
    echo '  ligand ', $ligand, "\n";
}
echo '1FB4 is now ', $pdb->entity('1FB4')?->id, "\n";

$page = $pdb->search(new Query('hemoglobin', limit: 3));
printf("%d entries for \"hemoglobin\"; the first %d: %s\n", $page->total, count($page), implode(', ', array_map(static fn ($e) => $e->id->value, $page->items)));
```

```
pdb:4HHB THE CRYSTAL STRUCTURE OF HUMAN DEOXYHAEMOGLOBIN AT 1.74 ANGSTROMS RESOLUTION
  X-RAY DIFFRACTION, 1.74 Å, 64.74 kDa, proteins P69905, P68871
  ligand HEM: PROTOPORPHYRIN IX CONTAINING FE (C34 H32 Fe N4 O4)
  ligand PO4: PHOSPHATE ION (O4 P)
1FB4 is now pdb:2FB4
9218 entries for "hemoglobin"; the first 3: 2PGH, 3PEL, 3GOU
```

(as answered on 2026-10-27: six calls, 0.25 s apart)

```yaml
# config/packages/omnilab.yaml
omnilab:
    sources:
        pdb: { factory: pdb, options: { mailto: '%env(OMNILAB_MAILTO)%' } }
```

## Errors

Every call goes through `HttpSource`: a 404 or a 410 (and a 204) is null - an empty page for a list -; a 429 is a `RateLimitedException` (`retryAfter` from `Retry-After`); a 5xx, a time-out or a host that does not answer an `UnavailableException`; any other 4xx a `ProviderException` with its `status` and what the service said; an answer that is not JSON a `ProviderException`, a web page answered for JSON an `UnavailableException`.

RCSB's own: GraphQL's errors come with a 200 and are thrown as a `ProviderException`
("GraphQL answered: ..."); a search that matches nothing is the Search API's 204, an empty page.
A removed entry replaced by several, or an unknown id, is null. Before any call: a UniProt
accession, a name, any other scheme (`NotSupportedException`).

## Verified, and not

Against the real services on 2026-10-15, without a key: 4HHB (1.74 Å, X-ray, 64.74 kDa, 4,779
atoms, two polymer entities, heme and phosphate, its citation, its picture), 1A3N, 6VXX (the
SARS-CoV-2 spike, electron microscopy at 2.8 Å, P0DTC2, its ligand by ChEBI), 9ZZZ (a nucleosome
released 2026-10-14, five UniProt proteins); `pdb_00004hhb`; 1FB4 replaced by 2FB4 alone, 1HHB
replaced by three (null), 0XXX unknown; the full-text search for "hemoglobin" (9,220 entries, two
pages of three, GraphQL's order differing from the search's), a search matching nothing (204); a
GraphQL error (a 200 with `errors`); the image URLs of 4HHB, 1A3N, 6VXX and 9ZZZ (200). The
fixtures in `Tests/Fixtures/` are its answers.

Again on 2026-10-27: 4HHB, 1FB4 replaced by 2FB4, the search for "hemoglobin" (9,218 entries;
the example above).

Not verified: RCSB's 429 (none met: the handling is the core's); an extended id that is not a
four-character code's (none met; it would be asked as it is, and get no picture).

## Responsibility

This package is an independent, unofficial client: it is not affiliated with, endorsed by or
sponsored by RCSB PDB or the wwPDB. The PDB archive's data are free of copyright (CC0 1.0, the
wwPDB's policy); cite the entry's primary citation and RCSB PDB where they are shown, and keep to
RCSB's rate limits, as the README's [Terms of use](https://github.com/glitchr-omnisense/omnilab-pdb#terms-of-use)
says. Whoever runs the package is responsible for any use made of what it returns: see the README's
[Responsibility](https://github.com/glitchr-omnisense/omnilab-pdb#responsibility) section.
