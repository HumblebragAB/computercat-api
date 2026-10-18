<?php

namespace Tests\Feature\Releases;

use App\Provisioning\Http\ExternalApiException;
use App\Releases\AppStore\AppStoreConnectApi;
use App\Releases\AppStore\AppStoreNotConfigured;
use App\Releases\AppStore\AppStoreSetupFailed;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Signer\Ecdsa\Sha256;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Token\Parser;
use Lcobucci\JWT\Validation\Constraint\SignedWith;
use Lcobucci\JWT\Validation\Validator;
use Tests\Support\AppStoreFakes as A;
use Tests\TestCase;

class AppStoreConnectApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        A::configure();
    }

    private function api(): AppStoreConnectApi
    {
        return app(AppStoreConnectApi::class);
    }

    public function test_jwt_is_es256_with_key_id_issuer_audience_and_15_minutes(): void
    {
        $token = (new Parser(new JoseEncoder))->parse($this->api()->jwt(new \DateTimeImmutable('@1760000000')));

        $this->assertTrue((new Validator)->validate($token, new SignedWith(new Sha256, InMemory::plainText(A::keyPair()['public']))));
        $this->assertSame('ES256', $token->headers()->get('alg'));
        $this->assertSame(A::KEY_ID, $token->headers()->get('kid'));
        $this->assertSame(A::ISSUER, $token->claims()->get('iss'));
        // Apple kräver aud som sträng, inte lista.
        $payload = json_decode(base64_decode(strtr(explode('.', $token->toString())[1], '-_', '+/')), true);
        $this->assertSame('appstoreconnect-v1', $payload['aud']);
        $this->assertSame(900, $payload['exp'] - $payload['iat']);
    }

    public function test_world_readable_key_is_refused_before_any_request(): void
    {
        A::configure(0644);
        Http::fake();

        try {
            $this->api()->findApp('se.computercat.kattsvep');
            $this->fail('Ingen kontroll av nyckelfilen.');
        } catch (AppStoreNotConfigured $e) {
            $this->assertStringContainsString('ASC_RELEASE_PRIVATE_KEY_PATH', $e->getMessage());
            $this->assertStringNotContainsString(A::KEY_ID, $e->getMessage());
        }
        Http::assertNothingSent();
    }

    public function test_missing_key_id_is_refused(): void
    {
        config(['releases.app_store.key_id' => '']);
        Http::fake();

        $this->expectException(AppStoreNotConfigured::class);
        $this->api()->findApp('se.computercat.kattsvep');
    }

    public function test_find_bundle_id_matches_exactly_and_create_sends_ios_platform(): void
    {
        Http::fake([
            A::API.'/v1/bundleIds?*' => Http::response(A::many(A::bundleId('se.computercat.kattsvep2', 'OTHER00001'), A::bundleId('se.computercat.kattsvep'))),
            A::API.'/v1/bundleIds' => Http::response(A::one(A::bundleId('se.computercat.ny', 'NEWID00001')), 201),
        ]);

        $this->assertSame(['id' => A::BUNDLE_RESOURCE, 'identifier' => 'se.computercat.kattsvep'], $this->api()->findBundleId('se.computercat.kattsvep'));
        $this->assertSame('NEWID00001', $this->api()->createBundleId('se.computercat.ny', 'ComputerCat Ny')['id']);

        Http::assertSent(fn (Request $r) => $r->method() === 'GET' && str_contains(urldecode($r->url()), 'filter[identifier]=se.computercat.kattsvep')
            && str_contains(urldecode($r->url()), 'filter[platform]=IOS') && str_starts_with($r->header('Authorization')[0], 'Bearer eyJ'));
        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && $r->data() === ['data' => ['type' => 'bundleIds', 'attributes' => ['identifier' => 'se.computercat.ny', 'name' => 'ComputerCat Ny', 'platform' => 'IOS']]]);
    }

    public function test_find_app_by_bundle_id_returns_id_name_and_sku(): void
    {
        Http::fake([A::API.'/v1/apps?*' => Http::response(A::many(A::app('se.computercat.kattsvep')))]);

        $this->assertSame(['id' => A::APP, 'name' => 'Kattsvep', 'sku' => 'kattsvep', 'bundle_id' => 'se.computercat.kattsvep'], $this->api()->findApp('se.computercat.kattsvep'));
        Http::assertSent(fn (Request $r) => str_contains(urldecode($r->url()), 'filter[bundleId]=se.computercat.kattsvep'));
    }

    public function test_app_with_another_bundle_id_is_not_returned(): void
    {
        Http::fake([A::API.'/v1/apps?*' => Http::response(A::many(A::app('se.computercat.kattsvepx')))]);

        $this->assertNull($this->api()->findApp('se.computercat.kattsvep'));
    }

    public function test_create_profile_sends_app_store_type_bundle_and_certificate(): void
    {
        Http::fake([A::API.'/v1/profiles' => Http::response(A::one(A::profile()), 201)]);

        $profile = $this->api()->createProfile('ComputerCat kattsvep App Store', A::BUNDLE_RESOURCE, A::CERT);

        $this->assertSame(A::PROFILE, $profile['id']);
        $this->assertSame('ACTIVE', $profile['state']);
        Http::assertSent(fn (Request $r) => $r->data() === ['data' => [
            'type' => 'profiles',
            'attributes' => ['name' => 'ComputerCat kattsvep App Store', 'profileType' => 'IOS_APP_STORE'],
            'relationships' => [
                'bundleId' => ['data' => ['type' => 'bundleIds', 'id' => A::BUNDLE_RESOURCE]],
                'certificates' => ['data' => [['type' => 'certificates', 'id' => A::CERT]]],
            ],
        ]]);
    }

    public function test_profile_is_read_without_profile_content(): void
    {
        Http::fake([A::API.'/v1/profiles/'.A::PROFILE.'?*' => Http::response(A::one(A::profile(state: 'INVALID')))]);

        $this->assertSame('INVALID', $this->api()->profile(A::PROFILE)['state']);
        Http::assertSent(fn (Request $r) => str_contains(urldecode($r->url()), 'fields[profiles]=name,profileState,expirationDate,uuid'));
    }

    public function test_beta_group_is_found_by_exact_internal_name_and_created_with_access_to_all_builds(): void // R7
    {
        Http::fake([
            A::API.'/v1/betaGroups?*' => Http::response(A::many(A::betaGroup('ext-1', 'ComputerCat intern', false), A::betaGroup())),
            A::API.'/v1/betaGroups' => Http::response(A::one(A::betaGroup('new-1')), 201),
        ]);

        $this->assertSame(A::GROUP, $this->api()->findBetaGroup(A::APP, 'ComputerCat intern')['id']);
        $this->assertSame('new-1', $this->api()->createInternalBetaGroup(A::APP, 'ComputerCat intern')['id']);
        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && $r->data() === ['data' => [
            'type' => 'betaGroups',
            'attributes' => ['name' => 'ComputerCat intern', 'isInternalGroup' => true, 'hasAccessToAllBuilds' => true],
            'relationships' => ['app' => ['data' => ['type' => 'apps', 'id' => A::APP]]],
        ]]);
    }

    public function test_user_is_read_by_id_with_visibility_and_the_email_never_reaches_a_log(): void // R18
    {
        Log::spy();
        $id = A::TESTER_IDS[0];
        Http::fake([
            A::API.'/v1/users/'.$id.'?*' => Http::response(A::one(A::user($id, 'erik@example.test', allAppsVisible: false))),
            A::API.'/v1/betaGroups/'.A::GROUP.'/betaTesters?*' => Http::response(A::error(500, 'UNEXPECTED_ERROR', 'erik@example.test failed'), 500),
        ]);

        $this->assertSame(['email' => 'erik@example.test', 'all_apps_visible' => false], $this->api()->user($id));
        Http::assertSent(fn (Request $r) => str_contains(urldecode($r->url()), 'fields[users]=username,allAppsVisible'));

        try {
            $this->api()->betaGroupHasTester(A::GROUP, 'erik@example.test');
            $this->fail('500 kastades inte.');
        } catch (ExternalApiException $e) {
            $this->assertStringNotContainsString('erik@example.test', $e->getMessage());
            $this->assertStringNotContainsString('erik%40example.test', $e->getMessage());
        }
        Log::shouldHaveReceived('warning')->withArgs(fn (string $msg, array $ctx) => ! str_contains(json_encode($ctx), 'erik@example.test') && ! str_contains(json_encode($ctx), 'erik%40example.test'))->once();
    }

    public function test_user_without_username_fails_loud(): void
    {
        $id = A::TESTER_IDS[0];
        Http::fake([A::API.'/v1/users/'.$id.'?*' => Http::response(A::one(['type' => 'users', 'id' => $id, 'attributes' => []]))]);

        $this->expectException(ExternalApiException::class);
        $this->api()->user($id);
    }

    public function test_user_without_all_apps_visible_counts_as_limited(): void
    {
        $id = A::TESTER_IDS[0];
        Http::fake([A::API.'/v1/users/'.$id.'?*' => Http::response(A::one(['type' => 'users', 'id' => $id, 'attributes' => ['username' => 'erik@example.test']]))]);

        $this->assertFalse($this->api()->user($id)['all_apps_visible']);
    }

    public function test_group_membership_is_read_from_the_group_case_insensitively(): void
    {
        Http::fake([A::API.'/v1/betaGroups/'.A::GROUP.'/betaTesters?*' => Http::response(A::many(A::betaTester('Erik@Example.TEST', 't-1')))]);

        $this->assertTrue($this->api()->betaGroupHasTester(A::GROUP, 'erik@example.test'));
        $this->assertFalse($this->api()->betaGroupHasTester(A::GROUP, 'mattias@example.test'));
        Http::assertSent(fn (Request $r) => $r->method() === 'GET'
            && urldecode($r->url()) === A::API.'/v1/betaGroups/'.A::GROUP.'/betaTesters?fields[betaTesters]=email&limit=200');
    }

    public function test_group_membership_with_more_pages_fails_loud_when_not_found(): void
    {
        Http::fake([A::API.'/v1/betaGroups/'.A::GROUP.'/betaTesters?*' => Http::response(['data' => [], 'links' => ['self' => 'x', 'next' => A::API.'/v1/next']])]);

        $this->expectException(AppStoreSetupFailed::class);
        $this->api()->betaGroupHasTester(A::GROUP, 'erik@example.test');
    }

    public function test_visible_apps_are_read_for_the_user(): void
    {
        $id = A::TESTER_IDS[0];
        Http::fake([A::API.'/v1/users/'.$id.'/visibleApps?*' => Http::response(A::many(A::app('se.computercat.annat', '111'), A::app('se.computercat.kattsvep')))]);

        $this->assertTrue($this->api()->userCanSeeApp($id, A::APP));
        $this->assertFalse($this->api()->userCanSeeApp($id, '222'));
        Http::assertSent(fn (Request $r) => urldecode($r->url()) === A::API.'/v1/users/'.$id.'/visibleApps?fields[apps]=bundleId&limit=200');
    }

    public function test_beta_tester_is_created_in_the_group(): void
    {
        Http::fake([A::API.'/v1/betaTesters' => Http::response(A::one(A::betaTester('erik@example.test', 'tester-1')), 201)]);

        $this->assertSame('tester-1', $this->api()->createBetaTester('erik@example.test', A::GROUP)['id']);

        Http::assertSent(fn (Request $r) => $r->url() === A::API.'/v1/betaTesters' && $r->data() === ['data' => [
            'type' => 'betaTesters',
            'attributes' => ['email' => 'erik@example.test'],
            'relationships' => ['betaGroups' => ['data' => [['type' => 'betaGroups', 'id' => A::GROUP]]]],
        ]]);
        $this->assertFalse(method_exists(AppStoreConnectApi::class, 'addTestersToGroup')); // den obevisade länkvägen är borta
    }

    public function test_find_build_filters_on_app_version_and_build_number(): void
    {
        Http::fake([A::API.'/v1/builds?*' => Http::response(A::many(A::build('build-1', 7, 'VALID')))]);

        $this->assertSame(['id' => 'build-1', 'state' => 'VALID', 'version' => '7', 'expired' => false], $this->api()->findBuild(A::APP, '1.2.0', 7));
        Http::assertSent(function (Request $r) {
            $url = urldecode($r->url());

            return str_contains($url, 'filter[app]='.A::APP) && str_contains($url, 'filter[version]=7') && str_contains($url, 'filter[preReleaseVersion.version]=1.2.0');
        });
    }

    public function test_build_is_added_to_the_group_with_a_linkage(): void
    {
        Http::fake([
            A::API.'/v1/builds?*' => Http::response(A::many()),
            A::API.'/v1/betaGroups/'.A::GROUP.'/relationships/builds' => Http::response('', 204),
        ]);

        $this->assertFalse($this->api()->buildIsInGroup(A::GROUP, 'build-1'));
        $this->api()->addBuildToGroup(A::GROUP, 'build-1');

        Http::assertSent(fn (Request $r) => str_contains(urldecode($r->url()), 'filter[betaGroups]='.A::GROUP) && str_contains(urldecode($r->url()), 'filter[id]=build-1'));
        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && $r->data() === ['data' => [['type' => 'builds', 'id' => 'build-1']]]);
    }

    public function test_4xx_is_logged_with_request_and_response_and_the_jwt_never_appears(): void
    {
        Log::spy();
        Http::fake([A::API.'/v1/bundleIds' => Http::response(A::error(409, 'ENTITY_ERROR.ATTRIBUTE.INVALID.DUPLICATE', 'An identifier with that value already exists.'), 409)]);

        try {
            $this->api()->createBundleId('se.computercat.kattsvep', 'ComputerCat Kattsvep');
            $this->fail('409 kastades inte.');
        } catch (ExternalApiException $e) {
            $this->assertStringContainsString('App Store Connect POST /v1/bundleIds returned 409', $e->getMessage());
            $this->assertStringContainsString('DUPLICATE', $e->getMessage());
            $this->assertDoesNotMatchRegularExpression('/eyJ[A-Za-z0-9_-]+\./', $e->getMessage());
        }

        Log::shouldHaveReceived('warning')->withArgs(fn (string $msg, array $ctx) => str_contains($msg, 'App Store Connect API svarade 409')
            && str_contains((string) $ctx['request'], 'se.computercat.kattsvep')
            && str_contains((string) $ctx['response'], 'DUPLICATE')
            && ! str_contains(json_encode($ctx), 'eyJ'))->once();
    }

    public function test_ids_from_callers_are_validated_before_the_url(): void
    {
        Http::fake();

        $this->expectException(\InvalidArgumentException::class);
        try {
            $this->api()->profile('../apps');
        } finally {
            Http::assertNothingSent();
        }
    }
}
