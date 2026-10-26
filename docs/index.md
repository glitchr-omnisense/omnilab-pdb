# omnilab/pdb

## Installation

```sh
composer require omnilab/pdb
```

```php
use Omnilab\Pdb\PdbSourceFactory;

$pdb = (new PdbSourceFactory($httpClient))->create(['mailto' => 'support@glitchr.io']);
```

With the Symfony bundle, the factory is registered as `pdb` (see the core's docs).

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

## Verified, and not

Against the real services on 2026-10-15, without a key: 4HHB (1.74 Å, X-ray, 64.74 kDa, 4,779
atoms, two polymer entities, heme and phosphate, its citation, its picture), 1A3N, 6VXX (the
SARS-CoV-2 spike, electron microscopy at 2.8 Å, P0DTC2, its ligand by ChEBI), 9ZZZ (a nucleosome
released 2026-10-14, five UniProt proteins); `pdb_00004hhb`; 1FB4 replaced by 2FB4 alone, 1HHB
replaced by three (null), 0XXX unknown; the full-text search for "hemoglobin" (9,220 entries, two
pages of three, GraphQL's order differing from the search's), a search matching nothing (204); a
GraphQL error (a 200 with `errors`); the image URLs of 4HHB, 1A3N, 6VXX and 9ZZZ (200). The
fixtures in `Tests/Fixtures/` are its answers.

Not verified: RCSB's 429 (none met: the handling is the core's); an extended id that is not a
four-character code's (none met; it would be asked as it is, and get no picture).
