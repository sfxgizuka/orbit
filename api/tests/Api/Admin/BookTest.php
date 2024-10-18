<?php

declare(strict_types=1);

namespace App\Tests\Api\Admin;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use ApiPlatform\Symfony\Bundle\Test\Client;
use App\DataFixtures\Factory\BookFactory;
use App\DataFixtures\Factory\UserFactory;
use App\Entity\Book;
use App\Enum\BookCondition;
use App\Repository\BookRepository;
use App\Tests\Api\Admin\Trait\UsersDataProviderTrait;
use App\Tests\Api\Security\TokenGenerator;
use App\Tests\Api\Trait\SerializerTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mercure\Update;
use Zenstruck\Foundry\FactoryCollection;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class BookTest extends ApiTestCase
{
    use Factories;
    use ResetDatabase;
    use SerializerTrait;
    use UsersDataProviderTrait;

    private Client $client;

    protected function setup(): void
    {
        $this->client = self::createClient();
    }

    #[Test]
    #[DataProvider(methodName: 'getNonAdminUsers')]
    public function asNonAdminUserICannotGetACollectionOfBooks(int $expectedCode, string $hydraDescription, ?UserFactory $userFactory): void
    {
        $options = [];
        if ($userFactory) {
            $token = self::getContainer()->get(TokenGenerator::class)->generateToken([
                'email' => $userFactory->create()->email,
            ]);
            $options['auth_bearer'] = $token;
        }

        $this->client->request('GET', '/admin/books', $options);

        self::assertResponseStatusCodeSame($expectedCode);
        self::assertResponseHeaderSame('content-type', 'application/problem+json; charset=utf-8');
        self::assertResponseHeaderSame('link', '<http://www.w3.org/ns/hydra/error>; rel="http://www.w3.org/ns/json-ld#error",<http://localhost/docs.jsonld>; rel="http://www.w3.org/ns/hydra/core#apiDocumentation"');
        self::assertJsonContains([
            '@type' => 'Error',
            'title' => 'An error occurred',
            'description' => $hydraDescription,
        ]);
    }

    #[Test]
    #[DataProvider(methodName: 'getUrls')]
    public function asAdminUserICanGetACollectionOfBooks(FactoryCollection $factory, string $url, int $hydraTotalItems, ?int $itemsPerPage = null): void
    {
        // Cannot use Factory as data provider because BookFactory has a service dependency
        $factory->create();

        $token = self::getContainer()->get(TokenGenerator::class)->generateToken([
            'email' => UserFactory::createOneAdmin()->email,
        ]);

        $response = $this->client->request('GET', $url, ['auth_bearer' => $token]);

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('content-type', 'application/ld+json; charset=utf-8');
        self::assertEquals('<https://localhost/.well-known/mercure>; rel="mercure"', $response->getHeaders()['link'][1]);
        self::assertJsonContains([
            'hydra:totalItems' => $hydraTotalItems,
        ]);
        self::assertCount(min($itemsPerPage ?? $hydraTotalItems, 30), $response->toArray()['hydra:member']);
        self::assertMatchesJsonSchema(file_get_contents(__DIR__ . '/schemas/Book/collection.json'));
    }

    public static function getUrls(): iterable
    {
        yield 'all books' => [
            BookFactory::new()->many(35),
            '/admin/books',
            35,
        ];
        yield 'all books using itemsPerPage' => [
            BookFactory::new()->many(35),
            '/admin/books?itemsPerPage=10',
            35,
            10,
        ];
        yield 'books filtered by title' => [
            BookFactory::new()->sequence(static function () {
                yield ['title' => 'Hyperion'];
                foreach (range(1, 10) as $i) {
                    yield [];
                }
            }),
            '/admin/books?title=yperio',
            1,
        ];
        yield 'books filtered by author' => [
            BookFactory::new()->sequence(static function () {
                yield ['author' => 'Dan Simmons'];
                foreach (range(1, 10) as $i) {
                    yield [];
                }
            }),
            '/admin/books?author=simmons',
            1,
        ];
        yield 'books filtered by condition' => [
            BookFactory::new()->sequence(static function () {
                foreach (range(1, 100) as $i) {
                    // 33% of books are damaged
                    yield ['condition' => $i % 3 ? BookCondition::NewCondition : BookCondition::DamagedCondition];
                }
            }),
            '/admin/books?condition=' . BookCondition::DamagedCondition->value,
            33,
        ];
    }

    #[Test]
    public function asAdminUserICanGetACollectionOfBooksOrderedByTitle(): void
    {
        BookFactory::createOne(['title' => 'Hyperion']);
        BookFactory::createOne(['title' => 'The Wandering Earth']);
        BookFactory::createOne(['title' => 'Ball Lightning']);

        $token = self::getContainer()->get(TokenGenerator::class)->generateToken([
            'email' => UserFactory::createOneAdmin()->email,
        ]);

        $response = $this->client->request('GET', '/admin/books?order[title]=asc', ['auth_bearer' => $token]);

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('content-type', 'application/ld+json; charset=utf-8');
        self::assertEquals('<https://localhost/.well-known/mercure>; rel="mercure"', $response->getHeaders()['link'][1]);
        self::assertEquals('Ball Lightning', $response->toArray()['hydra:member'][0]['title']);
        self::assertEquals('Hyperion', $response->toArray()['hydra:member'][1]['title']);
        self::assertEquals('The Wandering Earth', $response->toArray()['hydra:member'][2]['title']);
        self::assertMatchesJsonSchema(file_get_contents(__DIR__ . '/schemas/Book/collection.json'));
    }

    #[Test]
    #[DataProvider(methodName: 'getAllUsers')]
    public function asAnyUserICannotGetAnInvalidBook(?UserFactory $userFactory): void
    {
        BookFactory::createOne();

        $options = [];
        if ($userFactory) {
            $token = self::getContainer()->get(TokenGenerator::class)->generateToken([
                'email' => $userFactory->create()->email,
            ]);
            $options['auth_bearer'] = $token;
        }

        $this->client->request('GET', '/admin/books/invalid', $options);

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public static function getAllUsers(): iterable
    {
        yield [null];
        yield [UserFactory::new()];
        yield [UserFactory::new()->withAdmin()];
    }

    #[Test]
    #[DataProvider(methodName: 'getNonAdminUsers')]
    public function asNonAdminUserICannotGetABook(int $expectedCode, string $hydraDescription, ?UserFactory $userFactory): void
    {
        $book = BookFactory::createOne();

        $options = [];
        if ($userFactory) {
            $token = self::getContainer()->get(TokenGenerator::class)->generateToken([
                'email' => $userFactory->create()->email,
            ]);
            $options['auth_bearer'] = $token;
        }

        $this->client->request('GET', '/admin/books/' . $book->getId(), $options);

        self::assertResponseStatusCodeSame($expectedCode);
        self::assertResponseHeaderSame('content-type', 'application/problem+json; charset=utf-8');
        self::assertResponseHeaderSame('link', '<http://www.w3.org/ns/hydra/error>; rel="http://www.w3.org/ns/json-ld#error",<http://localhost/docs.jsonld>; rel="http://www.w3.org/ns/hydra/core#apiDocumentation"');
        self::assertJsonContains([
            '@type' => 'Error',
            'title' => 'An error occurred',
            'description' => $hydraDescription,
        ]);
    }

    #[Test]
    #[DataProvider(methodName: 'getNonAdminUsers')]
    public function asAdminUserICanGetABook(): void
    {
        $book = BookFactory::createOne();

        $token = self::getContainer()->get(TokenGenerator::class)->generateToken([
            'email' => UserFactory::createOneAdmin()->email,
        ]);

        $response = $this->client->request('GET', '/admin/books/' . $book->getId(), ['auth_bearer' => $token]);

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('content-type', 'application/ld+json; charset=utf-8');
        self::assertEquals('<https://localhost/.well-known/mercure>; rel="mercure"', $response->getHeaders(false)['link'][1]);
        self::assertJsonContains([
            '@id' => '/admin/books/' . $book->getId(),
            'book' => $book->book,
            'condition' => $book->condition->value,
            'title' => $book->title,
            'author' => $book->author,
        ]);
        self::assertMatchesJsonSchema(file_get_contents(__DIR__ . '/schemas/Book/item.json'));
    }

    #[Test]
    #[DataProvider(methodName: 'getNonAdminUsers')]
    public function asNonAdminUserICannotCreateABook(int $expectedCode, string $hydraDescription, ?UserFactory $userFactory): void
    {
        $options = [];
        if ($userFactory) {
            $token = self::getContainer()->get(TokenGenerator::class)->generateToken([
                'email' => $userFactory->create()->email,
            ]);
            $options['auth_bearer'] = $token;
        }

        $this->client->request('POST', '/admin/books', $options + [
            'json' => [
                'book' => 'https://openlibrary.org/books/OL28346544M.json',
                'condition' => BookCondition::NewCondition->value,
            ],
            'headers' => [
                'Content-Type' => 'application/ld+json',
                'Accept' => 'application/ld+json',
            ],
        ]);

        self::assertResponseStatusCodeSame($expectedCode);
        self::assertResponseHeaderSame('content-type', 'application/problem+json; charset=utf-8');
        self::assertResponseHeaderSame('link', '<http://www.w3.org/ns/hydra/error>; rel="http://www.w3.org/ns/json-ld#error",<http://localhost/docs.jsonld>; rel="http://www.w3.org/ns/hydra/core#apiDocumentation"');
        self::assertJsonContains([
            '@type' => 'Error',
            'title' => 'An error occurred',
            'description' => $hydraDescription,
        ]);
    }

    #[Test]
    #[DataProvider(methodName: 'getInvalidDataOnCreate')]
    public function asAdminUserICannotCreateABookWithInvalidData(array $data, int $statusCode, array $expected): void
    {
        $token = self::getContainer()->get(TokenGenerator::class)->generateToken([
            'email' => UserFactory::createOneAdmin()->email,
        ]);

        $this->client->request('POST', '/admin/books', [
            'auth_bearer' => $token,
            'json' => $data,
            'headers' => [
                'Content-Type' => 'application/ld+json',
                'Accept' => 'application/ld+json',
            ],
        ]);

        self::assertResponseStatusCodeSame($statusCode);
        self::assertResponseHeaderSame('content-type', 'application/problem+json; charset=utf-8');
        self::assertResponseHeaderSame('link', '<http://www.w3.org/ns/hydra/error>; rel="http://www.w3.org/ns/json-ld#error",<http://localhost/docs.jsonld>; rel="http://www.w3.org/ns/hydra/core#apiDocumentation"');
        self::assertJsonContains($expected);
    }

    public static function getInvalidDataOnCreate(): iterable
    {
        yield 'no data' => [
            [],
            Response::HTTP_UNPROCESSABLE_ENTITY,
            [
                '@type' => 'ConstraintViolationList',
                'title' => 'An error occurred',
                'violations' => [
                    [
                        'propertyPath' => 'book',
                        'message' => 'This value should not be blank.',
                    ],
                    [
                        'propertyPath' => 'condition',
                        'message' => 'This value should not be null.',
                    ],
                ],
            ],
        ];
        yield from self::getInvalidData();
    }

    public static function getInvalidData(): iterable
    {
        yield 'empty data' => [
            [
                'book' => '',
                'condition' => '',
            ],
            Response::HTTP_UNPROCESSABLE_ENTITY,
            [
                '@type' => 'ConstraintViolationList',
                'title' => 'An error occurred',
                'description' => 'condition: This value should be of type ' . BookCondition::class . '.',
                'violations' => [
                    [
                        'propertyPath' => 'condition',
                        'hint' => 'The data must belong to a backed enumeration of type ' . BookCondition::class,
                    ],
                ],
            ],
        ];
        yield 'invalid condition' => [
            [
                'book' => 'https://openlibrary.org/books/OL28346544M.json',
                'condition' => 'invalid condition',
            ],
            Response::HTTP_UNPROCESSABLE_ENTITY,
            [
                '@type' => 'ConstraintViolationList',
                'title' => 'An error occurred',
                'description' => 'condition: This value should be of type ' . BookCondition::class . '.',
                'violations' => [
                    [
                        'propertyPath' => 'condition',
                        'hint' => 'The data must belong to a backed enumeration of type ' . BookCondition::class,
                    ],
                ],
            ],
        ];
        yield 'invalid book' => [
            [
                'book' => 'invalid book',
                'condition' => BookCondition::NewCondition->value,
            ],
            Response::HTTP_UNPROCESSABLE_ENTITY,
            [
                '@type' => 'ConstraintViolationList',
                'title' => 'An error occurred',
                'violations' => [
                    [
                        'propertyPath' => 'book',
                        'message' => 'This value is not a valid URL.',
                    ],
                ],
            ],
        ];
    }

    /**
     * @group apiCall
     * @group mercure
     */
    #[Test]
    public function asAdminUserICanCreateABook(): void
    {
        $token = self::getContainer()->get(TokenGenerator::class)->generateToken([
            'email' => UserFactory::createOneAdmin()->email,
        ]);

        $response = $this->client->request('POST', '/admin/books', [
            'auth_bearer' => $token,
            'json' => [
                'book' => 'https://openlibrary.org/books/OL28346544M.json',
                'condition' => BookCondition::NewCondition->value,
            ],
            'headers' => [
                'Content-Type' => 'application/ld+json',
                'Accept' => 'application/ld+json',
            ],
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        self::assertResponseHeaderSame('content-type', 'application/ld+json; charset=utf-8');
        self::assertEquals('<https://localhost/.well-known/mercure>; rel="mercure"', $response->getHeaders(false)['link'][1]);
        self::assertJsonContains([
            'book' => 'https://openlibrary.org/books/OL28346544M.json',
            'condition' => BookCondition::NewCondition->value,
            'title' => 'Foundation',
            'author' => 'Isaac Asimov',
        ]);
        self::assertMatchesJsonSchema(file_get_contents(__DIR__ . '/schemas/Book/item.json'));
        $id = preg_replace('/^.*\/(.+)$/', '$1', $response->toArray()['@id']);
        /** @var Book $book */
        $book = self::getContainer()->get(BookRepository::class)->find($id);
        self::assertCount(1, self::getMercureMessages());
        self::assertEquals(
            new Update(
                topics: ['http://localhost/admin/books/' . $book->getId(), 'http://localhost/books/' . $book->getId()],
                data: self::serialize(
                    $book,
                    'jsonld',
                    self::getOperationNormalizationContext(Book::class, '/admin/books/{id}{._format}')
                ),
            ),
            self::getMercureMessage()
        );
    }

    #[Test]
    #[DataProvider(methodName: 'getNonAdminUsers')]
    public function asNonAdminUserICannotUpdateBook(int $expectedCode, string $hydraDescription, ?UserFactory $userFactory): void
    {
        $book = BookFactory::createOne();

        $options = [];
        if ($userFactory) {
            $token = self::getContainer()->get(TokenGenerator::class)->generateToken([
                'email' => $userFactory->create()->email,
            ]);
            $options['auth_bearer'] = $token;
        }

        $this->client->request('PUT', '/admin/books/' . $book->getId(), $options + [
            'json' => [
                'book' => 'https://openlibrary.org/books/OL28346544M.json',
                'condition' => BookCondition::NewCondition->value,
            ],
            'headers' => [
                'Content-Type' => 'application/ld+json',
                'Accept' => 'application/ld+json',
            ],
        ]);

        self::assertResponseStatusCodeSame($expectedCode);
        self::assertResponseHeaderSame('content-type', 'application/problem+json; charset=utf-8');
        self::assertResponseHeaderSame('link', '<http://www.w3.org/ns/hydra/error>; rel="http://www.w3.org/ns/json-ld#error",<http://localhost/docs.jsonld>; rel="http://www.w3.org/ns/hydra/core#apiDocumentation"');
        self::assertJsonContains([
            '@type' => 'Error',
            'title' => 'An error occurred',
            'description' => $hydraDescription,
        ]);
    }

    #[Test]
    public function asAdminUserICannotUpdateAnInvalidBook(): void
    {
        BookFactory::createOne();

        $token = self::getContainer()->get(TokenGenerator::class)->generateToken([
            'email' => UserFactory::createOneAdmin()->email,
        ]);

        $this->client->request('PUT', '/admin/books/invalid', [
            'auth_bearer' => $token,
            'json' => [
                'condition' => BookCondition::DamagedCondition->value,
            ],
            'headers' => [
                'Content-Type' => 'application/ld+json',
                'Accept' => 'application/ld+json',
            ],
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    #[Test]
    #[DataProvider(methodName: 'getInvalidData')]
    public function asAdminUserICannotUpdateABookWithInvalidData(array $data, int $statusCode, array $expected): void
    {
        $book = BookFactory::createOne();

        $token = self::getContainer()->get(TokenGenerator::class)->generateToken([
            'email' => UserFactory::createOneAdmin()->email,
        ]);

        $this->client->request('PUT', '/admin/books/' . $book->getId(), [
            'auth_bearer' => $token,
            'json' => $data,
            'headers' => [
                'Content-Type' => 'application/ld+json',
                'Accept' => 'application/ld+json',
            ],
        ]);

        self::assertResponseStatusCodeSame($statusCode);
        self::assertResponseHeaderSame('content-type', 'application/problem+json; charset=utf-8');
        self::assertResponseHeaderSame('link', '<http://www.w3.org/ns/hydra/error>; rel="http://www.w3.org/ns/json-ld#error",<http://localhost/docs.jsonld>; rel="http://www.w3.org/ns/hydra/core#apiDocumentation"');
        self::assertJsonContains($expected);
    }

    /**
     * @group apiCall
     * @group mercure
     */
    #[Test]
    public function asAdminUserICanUpdateABook(): void
    {
        $book = BookFactory::createOne([
            'book' => 'https://openlibrary.org/books/OL28346544M.json',
        ]);
        self::getMercureHub()->reset();

        $token = self::getContainer()->get(TokenGenerator::class)->generateToken([
            'email' => UserFactory::createOneAdmin()->email,
        ]);

        $response = $this->client->request('PUT', '/admin/books/' . $book->getId(), [
            'auth_bearer' => $token,
            'json' => [
                '@id' => '/books/' . $book->getId(),
                // Must set all data because of standard PUT
                'book' => 'https://openlibrary.org/books/OL28346544M.json',
                'condition' => BookCondition::DamagedCondition->value,
            ],
            'headers' => [
                'Content-Type' => 'application/ld+json',
                'Accept' => 'application/ld+json',
            ],
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        self::assertResponseHeaderSame('content-type', 'application/ld+json; charset=utf-8');
        self::assertEquals('<https://localhost/.well-known/mercure>; rel="mercure"', $response->getHeaders(false)['link'][1]);
        self::assertJsonContains([
            'book' => 'https://openlibrary.org/books/OL28346544M.json',
            'condition' => BookCondition::DamagedCondition->value,
            'title' => 'Foundation',
            'author' => 'Isaac Asimov',
        ]);
        self::assertMatchesJsonSchema(file_get_contents(__DIR__ . '/schemas/Book/item.json'));
        self::assertCount(1, self::getMercureMessages());
        self::assertEquals(
            new Update(
                topics: ['http://localhost/admin/books/' . $book->getId(), 'http://localhost/books/' . $book->getId()],
                data: self::serialize(
                    $book->_real(),
                    'jsonld',
                    self::getOperationNormalizationContext(Book::class, '/admin/books/{id}{._format}')
                ),
            ),
            self::getMercureMessage()
        );
    }

    #[Test]
    #[DataProvider(methodName: 'getNonAdminUsers')]
    public function asNonAdminUserICannotDeleteABook(int $expectedCode, string $hydraDescription, ?UserFactory $userFactory): void
    {
        $book = BookFactory::createOne();

        $options = [];
        if ($userFactory) {
            $token = self::getContainer()->get(TokenGenerator::class)->generateToken([
                'email' => $userFactory->create()->email,
            ]);
            $options['auth_bearer'] = $token;
        }

        $this->client->request('DELETE', '/admin/books/' . $book->getId(), $options);

        self::assertResponseStatusCodeSame($expectedCode);
        self::assertResponseHeaderSame('content-type', 'application/problem+json; charset=utf-8');
        self::assertResponseHeaderSame('link', '<http://www.w3.org/ns/hydra/error>; rel="http://www.w3.org/ns/json-ld#error",<http://localhost/docs.jsonld>; rel="http://www.w3.org/ns/hydra/core#apiDocumentation"');
        self::assertJsonContains([
            '@type' => 'Error',
            'title' => 'An error occurred',
            'description' => $hydraDescription,
        ]);
    }

    #[Test]
    public function asAdminUserICannotDeleteAnInvalidBook(): void
    {
        BookFactory::createOne();

        $token = self::getContainer()->get(TokenGenerator::class)->generateToken([
            'email' => UserFactory::createOneAdmin()->email,
        ]);

        $this->client->request('DELETE', '/admin/books/invalid', ['auth_bearer' => $token]);

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    /**
     * @group mercure
     */
    #[Test]
    public function asAdminUserICanDeleteABook(): void
    {
        $book = BookFactory::createOne(['title' => 'Hyperion']);
        self::getMercureHub()->reset();
        $id = $book->getId();

        $token = self::getContainer()->get(TokenGenerator::class)->generateToken([
            'email' => UserFactory::createOneAdmin()->email,
        ]);

        $response = $this->client->request('DELETE', '/admin/books/' . $id, ['auth_bearer' => $token]);

        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
        self::assertEmpty($response->getContent());
        BookFactory::assert()->notExists(['title' => 'Hyperion']);
        self::assertCount(1, self::getMercureMessages());
        self::assertEquals(
            new Update(
                topics: ['http://localhost/admin/books/' . $id, 'http://localhost/books/' . $id],
                data: json_encode(['@id' => '/admin/books/' . $id, '@type' => ['https://schema.org/Book', 'https://schema.org/Offer']]),
            ),
            self::getMercureMessage()
        );
    }
}
