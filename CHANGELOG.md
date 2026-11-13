# Changelog

## [Unreleased]

## [1.0.0] - 2026-11-13

The first stable release: it installs with Composer's default minimum-stability (stable).

### Added

- Omnilab PDB: an entry of the Protein Data Bank by its PDB id through RCSB PDB's GraphQL Data API -
  its title, keywords, method, resolution, weight, dates, primary citation and authors, its polymer
  entities and ligands, an image of its assembly; the UniProt proteins of its chains, their
  organisms and its ligands (ChEBI, PubChem or InChIKey) as related things, never its identifiers; a
  removed entry's only replacement - and RCSB's full-text search, no key needed.

### Release

- Requires `omnisense/omnilab` `^1.0`, stable versions, where it required `@dev` ones:
  `composer require omnilab/pdb` installs in a project at Composer's default minimum-stability
  (stable). `extra.branch-alias` names the branch (`dev-1.x`: `1.x-dev`).
- CI: `.github/workflows/ci.yml` (glitchr-studio/ci's php-package.yml, pinned: the package
  installed alone, PHPUnit, PHPStan against its baseline, a consumer install) and `release.yml`
  (that CI on the tag, then glitchr-studio/ci's php-release.yml); `phpunit.xml.dist` runs the
  package's own tests.
- README: the install line and a first call.
