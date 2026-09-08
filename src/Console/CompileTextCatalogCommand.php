<?php

declare(strict_types=1);

namespace Lsr\TextCatalog\Console;

use InvalidArgumentException;
use Lsr\TextCatalog\TextCatalogCompiler;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'texts:cache:compile', description: 'Compile the configured text catalog and gettext artifacts.')]
final class CompileTextCatalogCommand extends Command
{
    public function __construct(private readonly TextCatalogCompiler $compiler) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int {
        try {
            $result = $this->compiler->compile();
        } catch (InvalidArgumentException | RuntimeException $exception) {
            $output->writeln('<error>' . OutputFormatter::escape($exception->getMessage()) . '</error>');
            return Command::FAILURE;
        }

        $output->writeln(sprintf('Compiled %d text catalog entries.', count($result->definition->texts)));
        return Command::SUCCESS;
    }
}
