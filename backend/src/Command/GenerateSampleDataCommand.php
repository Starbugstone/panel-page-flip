<?php

namespace App\Command;

use App\Entity\Comic;
use App\Entity\ComicReadingProgress;
use App\Entity\Tag;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Generates sample data for development and testing.
 *
 * This command creates sample users, comics, tags, and reading progress records
 * for development and testing purposes. It does not create actual CBZ files,
 * but only database records.
 *
 * Usage Examples:
 * --------------
 *
 * 1. Running via Docker (from your project's root directory where docker-compose.yml is):
 *    docker exec panel-page-flip_php php bin/console app:generate-sample-data
 *
 *    With specific counts:
 *    docker exec panel-page-flip_php php bin/console app:generate-sample-data --users=5 --comics=20 --tags=10
 *
 * 2. Running locally (if you have PHP and Composer installed directly on your machine and are in the `backend` directory):
 *    php bin/console app:generate-sample-data
 *
 * Options:
 *   --users=N    Number of sample users to create (default: 3)
 *   --comics=N   Number of sample comics to create (default: 10)
 *   --tags=N     Number of sample tags to create (default: 5)
 *   --force      Skip confirmation prompt
 *
 * Important Considerations:
 * - This command is intended for development and testing environments only
 * - It will create sample data in the database but not actual files on disk
 * - All sample users will have the password 'password'
 * - The command will ask for confirmation before creating data unless --force is used
 */
#[AsCommand(
    name: 'app:generate-sample-data',
    description: 'Generates sample data for development and testing.',
)]
class GenerateSampleDataCommand extends Command
{
    private EntityManagerInterface $entityManager;
    private UserPasswordHasherInterface $passwordHasher;
    private ParameterBagInterface $parameterBag;

    // Sample data
    private array $comicTitles = [
        'Batman: The Dark Knight Returns',
        'Watchmen',
        'Saga',
        'Sandman',
        'Maus',
        'V for Vendetta',
        'The Walking Dead',
        'Persepolis',
        'Akira',
        'Y: The Last Man',
        'Hellboy',
        'Sin City',
        'Preacher',
        'Transmetropolitan',
        'Fables',
        'The Killing Joke',
        'From Hell',
        'Planetary',
        'Blankets',
        'Kingdom Come',
    ];

    private array $authors = [
        'Frank Miller',
        'Alan Moore',
        'Brian K. Vaughan',
        'Neil Gaiman',
        'Art Spiegelman',
        'Robert Kirkman',
        'Marjane Satrapi',
        'Katsuhiro Otomo',
        'Mike Mignola',
        'Warren Ellis',
        'Grant Morrison',
        'Garth Ennis',
        'Craig Thompson',
        'Mark Millar',
        'Jeff Smith',
    ];

    private array $publishers = [
        'DC Comics',
        'Marvel Comics',
        'Image Comics',
        'Dark Horse Comics',
        'Vertigo',
        'IDW Publishing',
        'Boom! Studios',
        'Fantagraphics',
        'Drawn & Quarterly',
        'Oni Press',
    ];

    private array $tagNames = [
        'Superhero',
        'Horror',
        'Sci-Fi',
        'Fantasy',
        'Drama',
        'Comedy',
        'Action',
        'Adventure',
        'Mystery',
        'Thriller',
        'Crime',
        'Historical',
        'Biographical',
        'Romance',
        'Western',
        'War',
        'Political',
        'Slice of Life',
        'Supernatural',
        'Post-Apocalyptic',
    ];

    public function __construct(
        EntityManagerInterface $entityManager,
        UserPasswordHasherInterface $passwordHasher,
        ParameterBagInterface $parameterBag
    ) {
        parent::__construct();
        $this->entityManager = $entityManager;
        $this->passwordHasher = $passwordHasher;
        $this->parameterBag = $parameterBag;
    }

    protected function configure(): void
    {
        $this
            ->addOption('users', null, InputOption::VALUE_REQUIRED, 'Number of sample users to create', 3)
            ->addOption('comics', null, InputOption::VALUE_REQUIRED, 'Number of sample comics to create', 10)
            ->addOption('tags', null, InputOption::VALUE_REQUIRED, 'Number of sample tags to create', 5)
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Skip confirmation prompt');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $userCount = (int) $input->getOption('users');
        $comicCount = (int) $input->getOption('comics');
        $tagCount = (int) $input->getOption('tags');
        $force = $input->getOption('force');

        if ($userCount < 1 || $comicCount < 1 || $tagCount < 1) {
            $io->error('All counts must be at least 1.');
            return Command::FAILURE;
        }

        $io->title('Sample Data Generation');
        $io->section('This will create:');
        $io->listing([
            sprintf('%d sample users', $userCount),
            sprintf('%d sample comics', $comicCount),
            sprintf('%d sample tags', $tagCount),
            'Reading progress records for some comics',
        ]);

        if (!$force && !$io->confirm('Do you want to proceed?', false)) {
            $io->warning('Operation cancelled.');
            return Command::SUCCESS;
        }

        // Create sample tags
        $io->section('Creating sample tags...');
        $tags = $this->createSampleTags($tagCount, $io);

        // Create sample users
        $io->section('Creating sample users...');
        $users = $this->createSampleUsers($userCount, $io);

        // Create sample comics
        $io->section('Creating sample comics...');
        $comics = $this->createSampleComics($comicCount, $users, $tags, $io);

        // Create sample reading progress
        $io->section('Creating sample reading progress...');
        $this->createSampleReadingProgress($comics, $users, $io);

        $io->success('Sample data generated successfully!');
        return Command::SUCCESS;
    }

    private function createSampleTags(int $count, SymfonyStyle $io): array
    {
        $tags = [];
        $existingTags = $this->entityManager->getRepository(Tag::class)->findAll();
        
        // Use existing tags if available
        foreach ($existingTags as $tag) {
            $tags[] = $tag;
            $io->text(sprintf('Using existing tag: %s', $tag->getName()));
        }
        
        // Create new tags if needed
        $adminUser = $this->entityManager->getRepository(User::class)->findOneBy(['email' => 'admin@example.com']);
        if (!$adminUser) {
            $io->warning('Admin user not found. Using first available user as tag creator.');
            $adminUser = $this->entityManager->getRepository(User::class)->findOneBy([]);
            
            if (!$adminUser) {
                $io->warning('No users found. Creating a temporary user as tag creator.');
                $adminUser = new User();
                $adminUser->setEmail('temp_admin@example.com');
                $adminUser->setPassword('temp');
                $this->entityManager->persist($adminUser);
                $this->entityManager->flush();
            }
        }
        
        $tagNamesToCreate = array_slice($this->tagNames, 0, $count);
        foreach ($tagNamesToCreate as $tagName) {
            // Skip if tag already exists
            $existingTag = $this->entityManager->getRepository(Tag::class)->findOneBy(['name' => $tagName]);
            if ($existingTag) {
                continue;
            }
            
            $tag = new Tag();
            $tag->setName($tagName);
            $tag->setCreator($adminUser);
            
            $this->entityManager->persist($tag);
            $tags[] = $tag;
            
            $io->text(sprintf('Created tag: %s', $tagName));
        }
        
        $this->entityManager->flush();
        return $tags;
    }

    private function createSampleUsers(int $count, SymfonyStyle $io): array
    {
        $users = [];
        $existingUsers = $this->entityManager->getRepository(User::class)->findAll();
        
        // Use existing users if available
        foreach ($existingUsers as $user) {
            $users[] = $user;
            $io->text(sprintf('Using existing user: %s', $user->getEmail()));
        }
        
        // Create new users if needed
        $userCount = count($users);
        for ($i = $userCount; $i < $count; $i++) {
            $user = new User();
            $user->setEmail(sprintf('user%d@example.com', $i + 1));
            $user->setName(sprintf('User %d', $i + 1));
            $user->setPassword($this->passwordHasher->hashPassword($user, 'password'));
            
            $this->entityManager->persist($user);
            $users[] = $user;
            
            $io->text(sprintf('Created user: %s', $user->getEmail()));
        }
        
        $this->entityManager->flush();
        return $users;
    }

    private function createSampleComics(int $count, array $users, array $tags, SymfonyStyle $io): array
    {
        $comics = [];
        $existingComics = $this->entityManager->getRepository(Comic::class)->findAll();
        
        // Use existing comics if available
        foreach ($existingComics as $comic) {
            $comics[] = $comic;
            $io->text(sprintf('Using existing comic: %s', $comic->getTitle()));
        }
        
        // Create new comics if needed
        $comicCount = count($comics);
        for ($i = $comicCount; $i < $count; $i++) {
            $titleIndex = $i % count($this->comicTitles);
            $authorIndex = $i % count($this->authors);
            $publisherIndex = $i % count($this->publishers);
            
            $title = $this->comicTitles[$titleIndex];
            if ($i >= count($this->comicTitles)) {
                $title .= ' ' . ceil($i / count($this->comicTitles));
            }
            
            $comic = new Comic();
            $comic->setTitle($title);
            $comic->setAuthor($this->authors[$authorIndex]);
            $comic->setPublisher($this->publishers[$publisherIndex]);
            $comic->setDescription('This is a sample comic generated for testing purposes.');
            $comic->setFilePath(sprintf('sample-comic-%d.cbz', $i + 1));
            $comic->setPageCount(rand(20, 200));
            
            // Assign to a random user
            $userIndex = $i % count($users);
            $comic->setOwner($users[$userIndex]);
            
            // Add random tags (1-3 tags per comic)
            $tagCount = rand(1, min(3, count($tags)));
            $shuffledTags = $tags;
            shuffle($shuffledTags);
            for ($j = 0; $j < $tagCount; $j++) {
                $comic->addTag($shuffledTags[$j]);
            }
            
            $this->entityManager->persist($comic);
            $comics[] = $comic;
            
            $io->text(sprintf('Created comic: %s by %s', $title, $this->authors[$authorIndex]));
        }
        
        $this->entityManager->flush();
        return $comics;
    }

    private function createSampleReadingProgress(array $comics, array $users, SymfonyStyle $io): void
    {
        // Create reading progress for about 70% of comics
        $progressCount = 0;
        foreach ($comics as $comic) {
            // 70% chance of having reading progress
            if (rand(1, 10) <= 7) {
                $user = $comic->getOwner();
                
                // Check if progress already exists
                $existingProgress = $this->entityManager->getRepository(ComicReadingProgress::class)
                    ->findOneBy(['comic' => $comic, 'user' => $user]);
                
                if ($existingProgress) {
                    $io->text(sprintf('Reading progress already exists for comic: %s', $comic->getTitle()));
                    continue;
                }
                
                $progress = new ComicReadingProgress();
                $progress->setComic($comic);
                $progress->setUser($user);
                
                $pageCount = $comic->getPageCount() ?? 100;
                $currentPage = rand(1, $pageCount);
                $progress->setCurrentPage($currentPage);
                
                // 30% chance of being completed
                $completed = rand(1, 10) <= 3 || $currentPage === $pageCount;
                $progress->setCompleted($completed);
                
                // Random last read date in the past 30 days
                $daysAgo = rand(0, 30);
                $lastReadAt = new \DateTimeImmutable("-{$daysAgo} days");
                $progress->setLastReadAt($lastReadAt);
                
                $this->entityManager->persist($progress);
                $progressCount++;
                
                $io->text(sprintf(
                    'Created reading progress for comic: %s (Page %d/%d, %s)',
                    $comic->getTitle(),
                    $currentPage,
                    $pageCount,
                    $completed ? 'Completed' : 'In Progress'
                ));
            }
        }
        
        $this->entityManager->flush();
        $io->text(sprintf('Created %d reading progress records', $progressCount));
    }
}
