<?php
$finder = PhpCsFixer\Finder::create()->in([__DIR__.'/src', __DIR__.'/tests', __DIR__.'/migrations'])->append([__DIR__.'/public/router.php', __DIR__.'/public/index.php', __DIR__.'/bin/console']);
return (new PhpCsFixer\Config())->setRules(['@PSR12'=>true, 'no_unused_imports'=>true])->setFinder($finder);
