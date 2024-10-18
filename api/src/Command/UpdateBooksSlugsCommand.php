<?php

namespace App\Command;

use App\Repository\BookRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\String\Slugger\SluggerInterface;

#[AsCommand(
    name: 'app:update-book-slugs',
    description: 'Update book slugs where they are not set',
)]
class UpdateBooksSlugsCommand extends Command
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private BookRepository $bookRepository,
        private SluggerInterface $slugger
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $books = $this->bookRepository->findAll();
        foreach ($books as $book) {
            if (!isset($book->slug) || empty($book->slug)) {
                $book->slug = $this->slugger->slug($book->title)->lower();
                $output->writeln(sprintf('Updated slug for book: %s', $book->title));
            }
        }
        $this->entityManager->flush();

        $output->writeln('Book slugs have been updated.');

        return Command::SUCCESS;
    }
}
