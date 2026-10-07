<?php

namespace Voerro\Laravel\VisitorTracker\Test;

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Route;
use Voerro\Laravel\VisitorTracker\Jobs\GetGeoipData;
use Voerro\Laravel\VisitorTracker\Middleware\RecordVisits;
use Voerro\Laravel\VisitorTracker\Models\Visit;
use Voerro\Laravel\VisitorTracker\Tracker;

class UserAgentTest extends TestCase
{
    use RefreshDatabase;

    protected function getEnvironmentSetUp($app)
    {
        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('database.connections.sqlite.database', ':memory:');
        $app['config']->set('visitortracker.geoip_on', false);
    }

    public function testRequestWithoutUserAgentIsRecorded()
    {
        $this->assertRequestIsRecorded(null, '');
    }

    public function testRequestWithEmptyUserAgentIsRecorded()
    {
        $this->assertRequestIsRecorded('', '');
    }

    public function testRequestWithBrowserUserAgentIsRecorded()
    {
        $agent = 'Mozilla/5.0 (X11; Ubuntu; Linux x86_64; rv:58.0) Gecko/20100101 Firefox/58.0';

        $this->assertRequestIsRecorded($agent, $agent);

        $visit = Visit::first();
        $this->assertSame('firefox', $visit->browser_family);
        $this->assertSame('Firefox 58.0', $visit->browser);
    }

    public function testDirectTrackingWithoutUserAgentIsRecorded()
    {
        Bus::fake();
        $request = Request::create('http://localhost/', 'GET');
        $request->headers->remove('User-Agent');
        $request->server->remove('HTTP_USER_AGENT');
        $request->server->set('HTTP_ACCEPT_LANGUAGE', 'en-US');
        $this->app->instance('request', $request);

        $visit = Tracker::recordVisit();

        $this->assertSame('', $visit->user_agent);
        $this->assertCount(1, Visit::all());
        $this->assertSame('', Visit::first()->user_agent);
        Bus::assertDispatched(GetGeoipData::class);
    }

    private function assertRequestIsRecorded($agent, $expectedAgent)
    {
        Bus::fake();
        Route::get('/tracked', function (Request $request) {
            return response()->json([
                'user_agent' => $request->userAgent(),
                'header_present' => $request->headers->has('User-Agent'),
            ]);
        })->middleware(RecordVisits::class);

        $request = Request::create('http://localhost/tracked', 'GET');
        $request->server->set('HTTP_ACCEPT_LANGUAGE', 'en-US');
        // Request::create supplies a default Symfony user agent.
        if ($agent === null) {
            $request->headers->remove('User-Agent');
            $request->server->remove('HTTP_USER_AGENT');
        } else {
            $request->headers->set('User-Agent', $agent);
            $request->server->set('HTTP_USER_AGENT', $agent);
        }

        $kernel = $this->app->make(Kernel::class);
        $response = $kernel->handle($request);
        $kernel->terminate($request, $response);

        $this->assertSame(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertSame($agent, $data['user_agent']);
        $this->assertSame($agent !== null, $data['header_present']);

        $this->assertCount(1, Visit::all());
        $this->assertSame($expectedAgent, Visit::first()->user_agent);
        Bus::assertDispatched(GetGeoipData::class);
    }
}
