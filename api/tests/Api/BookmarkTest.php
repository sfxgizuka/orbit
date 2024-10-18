<?php

declare(strict_types=1);

namespace App\Tests\Api;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use ApiPlatform\Symfony\Bundle\Test\Client;
use App\DataFixtures\Factory\BookFactory;
use App\DataFixtures\Factory\BookmarkFactory;
use App\DataFixtures\Factory\UserFactory;
use App\Entity\Book;
use App\Entity\Bookmark;
use App\Repository\BookmarkRepository;
use App\Tests\Api\Security\TokenGenerator;
use App\Tests\Api\Trait\SerializerTrait;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class BookmarkTest extends ApiTestCase
{
    use Factories;
    use ResetDatabase;
    use SerializerTrait;

    private Client $client;

    protected function setup(): void
    {
        $this->client = self::createClient();
    }

    #[Test]
    public function asAnonymousICannotGetACollectionOfBookmarks(): void
    {
        BookmarkFactory::createMany(10);

        $this->client->request('GET', '/bookmarks');

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        self::assertResponseHeaderSame('content-type', 'application/problem+json; charset=utf-8');
        self::assertResponseHeaderSame('link', '<http://www.w3.org/ns/hydra/error>; rel="http://www.w3.org/ns/json-ld#error",<http://localhost/docs.jsonld>; rel="http://www.w3.org/ns/hydra/core#apiDocumentation"');
        self::assertJsonContains([
            '@type' => 'Error',
            'title' => 'An error occurred',
            'description' => 'Full authentication is required to access this resource.',
        ]);
    }

    /**
     * Filters are disabled on /bookmarks.
     */
    #[Test]
    public function asAUserICanGetACollectionOfMyBookmarksWithoutFilters(): void
    {
        BookmarkFactory::createMany(10);
        $user = UserFactory::createOne();
        BookmarkFactory::createMany(35, ['user' => $user]);

        $token = self::getContainer()->get(TokenGenerator::class)->generateToken([
            'email' => $user->email,
            'authorize' => true,
        ]);

        $response = $this->client->request('GET', '/bookmarks', ['auth_bearer' => $token]);

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('content-type', 'application/ld+json; charset=utf-8');
        self::assertEquals('<https://localhost/.well-known/mercure>; rel="mercure"', $response->getHeaders(false)['link'][1]);
        self::assertJsonContains([
            'hydra:totalItems' => 35,
        ]);
        self::assertCount(30, $response->toArray()['hydra:member']);
        self::assertMatchesJsonSchema(file_get_contents(__DIR__ . '/schemas/Bookmark/collection.json'));
    }

    #[Test]
    public function asAnonymousICannotCreateABookmark(): void
    {
        $book = BookFactory::createOne(['book' => 'https://openlibrary.org/books/OL2055137M.json']);

        $this->client->request('POST', '/bookmarks', [
            'json' => [
                'book' => '/books/' . $book->getId(),
            ],
            'headers' => [
                'Content-Type' => 'application/ld+json',
                'Accept' => 'application/ld+json',
            ],
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        self::assertResponseHeaderSame('content-type', 'application/problem+json; charset=utf-8');
        self::assertResponseHeaderSame('link', '<http://www.w3.org/ns/hydra/error>; rel="http://www.w3.org/ns/json-ld#error",<http://localhost/docs.jsonld>; rel="http://www.w3.org/ns/hydra/core#apiDocumentation"');
        self::assertJsonContains([
            '@type' => 'Error',
            'title' => 'An error occurred',
            'description' => 'Full authentication is required to access this resource.',
        ]);
    }

    #[Test]
    public function asAUserICannotCreateABookmarkWithInvalidData(): void
    {
        $token = self::getContainer()->get(TokenGenerator::class)->generateToken([
            'email' => UserFactory::createOne()->email,
            'authorize' => true,
        ]);

        $uuid = Uuid::v7()->__toString();

        $this->client->request('POST', '/bookmarks', [
            'json' => [
                'book' => '/books/' . $uuid,
            ],
            'headers' => [
                'Content-Type' => 'application/ld+json',
                'Accept' => 'application/ld+json',
            ],
            'auth_bearer' => $token,
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertResponseHeaderSame('content-type', 'application/problem+json; charset=utf-8');
        self::assertResponseHeaderSame('link', '<http://www.w3.org/ns/hydra/error>; rel="http://www.w3.org/ns/json-ld#error",<http://localhost/docs.jsonld>; rel="http://www.w3.org/ns/hydra/core#apiDocumentation"');
        self::assertJsonContains([
            '@type' => 'ConstraintViolationList',
            'title' => 'An error occurred',
            'description' => 'book: This value should be of type ' . Book::class . '.',
            'violations' => [
                [
                    'propertyPath' => 'book',
                    'hint' => 'Item not found for "/books/' . $uuid . '".',
                ],
            ],
        ]);
    }

    /**
     * @group mercure
     */
    #[Test]
    public function asAUserICanCreateABookmark(): void
    {
        $book = BookFactory::createOne(['book' => 'https://openlibrary.org/books/OL2055137M.json']);
        $user = UserFactory::createOne();
        self::getMercureHub()->reset();

        $token = self::getContainer()->get(TokenGenerator::class)->generateToken([
            'email' => $user->email,
            'authorize' => true,
        ]);

        $response = $this->client->request('POST', '/bookmarks', [
            'json' => [
                'book' => '/books/' . $book->getId(),
            ],
            'headers' => [
                'Content-Type' => 'application/ld+json',
                'Accept' => 'application/ld+json',
            ],
            'auth_bearer' => $token,
        ]);

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('content-type', 'application/ld+json; charset=utf-8');
        self::assertEquals('<https://localhost/.well-known/mercure>; rel="mercure"', $response->getHeaders(false)['link'][1]);
        self::assertJsonContains([
            'book' => [
                '@id' => '/books/' . $book->getId(),
            ],
        ]);
        self::assertMatchesJsonSchema(file_get_contents(__DIR__ . '/schemas/Bookmark/item.json'));
        $id = preg_replace('/^.*\/(.+)$/', '$1', $response->toArray()['@id']);
        $object = self::getContainer()->get(BookmarkRepository::class)->find($id);
        self::assertCount(1, self::getMercureMessages());
        self::assertEquals(
            self::getMercureMessage(),
            new Update(
                topics: ['http://localhost/bookmarks/' . $id],
                data: self::serialize(
                    $object,
                    'jsonld',
                    self::getOperationNormalizationContext(Bookmark::class, '/bookmarks/{id}{._format}')
                ),
            )
        );
    }

    #[Test]
    public function asAUserICannotCreateADuplicateBookmark(): void
    {
        $book = BookFactory::createOne(['book' => 'https://openlibrary.org/books/OL2055137M.json']);
        $user = UserFactory::createOne();
        BookmarkFactory::createOne(['book' => $book, 'user' => $user]);

        $token = self::getContainer()->get(TokenGenerator::class)->generateToken([
            'email' => $user->email,
            'authorize' => true,
        ]);

        $this->client->request('POST', '/bookmarks', [
            'json' => [
                'book' => '/books/' . $book->getId(),
            ],
            'headers' => [
                'Content-Type' => 'application/ld+json',
                'Accept' => 'application/ld+json',
            ],
            'auth_bearer' => $token,
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertResponseHeaderSame('content-type', 'application/problem+json; charset=utf-8');
        self::assertResponseHeaderSame('link', '<http://www.w3.org/ns/hydra/error>; rel="http://www.w3.org/ns/json-ld#error",<http://localhost/docs.jsonld>; rel="http://www.w3.org/ns/hydra/core#apiDocumentation"');
        self::assertJsonContains([
            '@type' => 'ConstraintViolationList',
            'title' => 'An error occurred',
            'description' => 'You have already bookmarked this book.',
        ]);
    }

    #[Test]
    public function asAnonymousICannotDeleteABookmark(): void
    {
        $bookmark = BookmarkFactory::createOne();

        $this->client->request('DELETE', '/bookmarks/' . $bookmark->getId());

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        self::assertResponseHeaderSame('content-type', 'application/problem+json; charset=utf-8');
        self::assertResponseHeaderSame('link', '<http://www.w3.org/ns/hydra/error>; rel="http://www.w3.org/ns/json-ld#error",<http://localhost/docs.jsonld>; rel="http://www.w3.org/ns/hydra/core#apiDocumentation"');
        self::assertJsonContains([
            '@type' => 'Error',
            'title' => 'An error occurred',
            'description' => 'Full authentication is required to access this resource.',
        ]);
    }

    #[Test]
    public function asAUserICannotDeleteABookmarkOfAnotherUser(): void
    {
        $bookmark = BookmarkFactory::createOne(['user' => UserFactory::createOne()]);

        $token = self::getContainer()->get(TokenGenerator::class)->generateToken([
            'email' => UserFactory::createOne()->email,
            'authorize' => false,
        ]);

        $this->client->request('DELETE', '/bookmarks/' . $bookmark->getId(), [
            'auth_bearer' => $token,
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertResponseHeaderSame('content-type', 'application/problem+json; charset=utf-8');
        self::assertResponseHeaderSame('link', '<http://www.w3.org/ns/hydra/error>; rel="http://www.w3.org/ns/json-ld#error",<http://localhost/docs.jsonld>; rel="http://www.w3.org/ns/hydra/core#apiDocumentation"');
        self::assertJsonContains([
            '@type' => 'Error',
            'title' => 'An error occurred',
            'description' => 'Access Denied.',
        ]);
    }

    #[Test]
    public function asAUserICannotDeleteAnInvalidBookmark(): void
    {
        $token = self::getContainer()->get(TokenGenerator::class)->generateToken([
            'email' => UserFactory::createOne()->email,
        ]);

        $this->client->request('DELETE', '/bookmarks/invalid', [
            'auth_bearer' => $token,
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    /**
     * @group mercure
     */
    #[Test]
    public function asAUserICanDeleteMyBookmark(): void
    {
        $book = BookFactory::createOne(['title' => 'Hyperion']);
        $bookmark = BookmarkFactory::createOne(['book' => $book]);
        self::getMercureHub()->reset();

        $id = $bookmark->getId();

        $token = self::getContainer()->get(TokenGenerator::class)->generateToken([
            'email' => $bookmark->user->email,
            'authorize' => true,
        ]);

        $response = $this->client->request('DELETE', '/bookmarks/' . $bookmark->getId(), [
            'auth_bearer' => $token,
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
        self::assertEmpty($response->getContent());
        BookmarkFactory::assert()->notExists(['book' => $book]);
        self::assertCount(1, self::getMercureMessages());
        self::assertEquals(
            new Update(
                topics: ['http://localhost/bookmarks/' . $id],
                data: json_encode(['@id' => '/bookmarks/' . $id, '@type' => 'https://schema.org/BookmarkAction']),
            ),
            self::getMercureMessage()
        );
    }
}
