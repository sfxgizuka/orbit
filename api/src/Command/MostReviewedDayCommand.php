<?php

namespace App\Command;

use App\Repository\ReviewRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:most-reviewed-day',
    description: 'Displays the day or month with the highest number of published reviews',
)]
class MostReviewedDayCommand extends Command
{
    public function __construct(
        private ReviewRepository $reviewRepository
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('month', 'm', InputOption::VALUE_NONE, 'Display the month instead of the day');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $isMonth = $input->getOption('month');
        $format = $isMonth ? 'Y-m' : 'Y-m-d';
        $groupBy = $isMonth ? 'DATE_FORMAT(r.publicationDate, \'%Y-%m\')' : 'DATE(r.publicationDate)';

        $result = $this->reviewRepository->findMostReviewedDate($groupBy);

        if (!$result) {
            $output->writeln('No reviews found.');
            return Command::SUCCESS;
        }

        $date = new \DateTime($result['date']);
        $output->writeln(sprintf(
            'The %s with the most reviews (%d) is: %s',
            $isMonth ? 'month' : 'day',
            $result['count'],
            $date->format($format)
        ));

        return Command::SUCCESS;
    }
}
