<?php

use DG\BypassFinals;
use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

// Services are `final` on purpose — a deletion rule or an import rule that can
// be subclassed is one that will eventually disagree with itself. That keyword
// is a statement about production, though, not about whether a thin console
// command may be tested against a stubbed collaborator, so it is lifted here
// and only here.
//
// Scoped to our own src/: vendor code (Doctrine's proxies especially) relies on
// its own finality, and stripping it everywhere buys nothing.
BypassFinals::setWhitelist([dirname(__DIR__).'/src/*']);
BypassFinals::enable();

if (method_exists(Dotenv::class, 'bootEnv')) {
    (new Dotenv())->bootEnv(dirname(__DIR__).'/.env');
}
