# omnilab/pdb

The Protein Data Bank for [omniscient/omnilab](https://github.com/glitchr-studio/omnilab), through the
APIs of RCSB PDB: an entry by its PDB id - its title, keywords, experimental method, resolution,
weight, dates, primary citation and authors, its polymer entities (the chains) and its ligands, a
picture of its first assembly; the UniProt proteins of its chains, their organisms and its ligands
as related things - a structure is not its protein: the `Collector` follows none of them -; the
entry that replaced a removed one; RCSB's full-text search. No key.

```php
$pdb = (new PdbSourceFactory($http))->create(['mailto' => 'support@glitchr.io']);
$entry = $pdb->entity('4HHB');                   // or 'pdb:4hhb', 'pdb_00004hhb', its RCSB page
$entry->label;                                   // THE CRYSTAL STRUCTURE OF HUMAN DEOXYHAEMOGLOBIN AT 1.74 ANGSTROMS RESOLUTION
$entry->property('resolution');                  // 1.74 Å
$entry->value('experimental_method');            // X-RAY DIFFRACTION
$entry->properties('polymer_entity');            // Hemoglobin subunit alpha (chains A, C; 141 residues; ...), ... beta
$entry->related('protein')->keys();              // uniprot:P69905, uniprot:P68871
$entry->related('ligand')->keys();               // inchikey:KABFMIBPWCXCRK-RGGAHWMASA-L (heme), chebi:18367 (phosphate)
$entry->image;                                   // https://cdn.rcsb.org/images/structures/hh/4hhb/4hhb_assembly-1.jpeg
$pdb->entity('1FB4')->id;                        // pdb:2FB4: 1FB4 was removed, 2FB4 replaced it
$pdb->search(new Query('hemoglobin', limit: 10));   // 9,220 entries, the best first
```

```yaml
omnilab:
    sources:
        pdb:
            factory: pdb
            options:
                mailto: 'support@glitchr.io'   # in the User-Agent
                throttle: 0.25                 # RCSB asks to start at a handful of calls a second
```

From UniProt, a protein's structures are related things (`$protein->related('structure')`): take
one alone, `$collector->collect('pdb', $id)`, and its own related proteins and ligands lead to
UniProt and ChEBI in turn. See [docs/](docs/index.md).

## Which API

The Data API's **GraphQL** endpoint (`data.rcsb.org/graphql`): one call gives an entry with its
polymer entities, their UniProt ids and organisms, and its ligands with their identifiers - the
REST API (`data.rcsb.org/rest/v1/core/entry/{id}`) needs one call for the entry, one per polymer
entity and one per ligand (five for 4HHB). The **Search API**
(`search.rcsb.org/rcsbsearch/v2/query`) for the full-text search: it answers ids only, so a page
costs it one call and GraphQL one more for the page's entries. The Data API's holdings
(`rest/v1/holdings/removed/{id}`) for an entry removed from the archive.

## Terms of use

The PDB archive's data are free of copyright (CC0 1.0, the wwPDB's policy); cite the entry's
primary citation, and RCSB PDB, where you show it. No key, no account. Every RCSB API is
rate-limited: RCSB asks a client to start at a handful of calls a second and answers 429 beyond (an
IP shared with others meets it sooner); the source leaves 0.25 s between two calls.

## Responsibility

This package is an independent, unofficial client: it is not affiliated with, endorsed by or
sponsored by the RCSB PDB and the wwPDB. It reaches their service on behalf of whoever runs it, and it does not
redistribute their data beyond the short excerpts recorded as test fixtures. Its MIT licence
covers this package's code only and grants no right to the service or to its data.

Whoever installs and runs this package is responsible for complying with the terms above, for
obtaining whatever they require (an account, a key, a licence or subscription, an attribution or
a citation, a rate limit to respect), and for any use made of what it returns. The authors
publish it free of charge, make no commercial use of it, and accept no liability for how it is
used (see [LICENSE](LICENSE)).

License: MIT.
