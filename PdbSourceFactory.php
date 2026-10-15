<?php

namespace Omnilab\Pdb;

use Omnilab\Config;
use Omnilab\Source\SourceFactory;
use Omnilab\Source\SourceInterface;
use Symfony\Component\HttpClient\HttpClient;

/**
 * The Protein Data Bank, through RCSB PDB's APIs: an entry by its PDB id -
 * its title, method, resolution, dates, citation, its polymer entities with
 * their UniProt proteins and organisms, its ligands with their chemical
 * identifiers - and RCSB's full-text search - no key needed.
 *
 *   options:
 *     mailto: 'support@glitchr.io'     # in the User-Agent: whom RCSB writes to before it blocks a caller
 *     throttle: 0.25                   # seconds between two calls: RCSB asks to start at a handful a second, and answers 429 beyond its limit
 *     base_uri: 'https://data.rcsb.org/'                            # the Data API: GraphQL, and the holdings of removed entries
 *     search_uri: 'https://search.rcsb.org/rcsbsearch/v2/query'     # the Search API
 */
final class PdbSourceFactory extends SourceFactory
{
    protected function populate(Config $c): void
    {
        $c->defaults([
            'omnilab.factory_name' => 'pdb',
            'omnilab.required_options' => [],
            'throttle' => PdbSource::THROTTLE,
            'base_uri' => PdbSource::BASE_URI,
            'search_uri' => PdbSource::SEARCH_URI,
        ]);
    }

    protected function build(Config $c): SourceInterface
    {
        return new PdbSource(
            $this->http ?? HttpClient::create(),
            (string) $c->get('search_uri', PdbSource::SEARCH_URI),
            (string) $c->get('base_uri', PdbSource::BASE_URI),
            ['User-Agent' => self::userAgent($c)],
            (float) $c->get('throttle'),
        );
    }
}
