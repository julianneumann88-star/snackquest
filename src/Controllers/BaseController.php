<?php
/**
 * SnackQuest — controller base: service wiring and current-user helpers.
 * Version: 1.0.0 (2026-07-21)
 */
declare(strict_types=1);

namespace SnackQuest\Controllers;

use SnackQuest\App;
use SnackQuest\Auth\AuthService;
use SnackQuest\Auth\GoogleOAuth;
use SnackQuest\Auth\Mailer;
use SnackQuest\Http\Response;
use SnackQuest\Http\Session;
use SnackQuest\Services\GameService;
use SnackQuest\Services\AiInsightService;
use SnackQuest\Services\ShareService;
use SnackQuest\Services\OpenFoodFactsService;
use SnackQuest\Services\ProductService;
use SnackQuest\Services\UploadService;
use SnackQuest\Support\CurlHttpClient;
use SnackQuest\Support\HttpClient;
use SnackQuest\Support\View;

abstract class BaseController
{
    protected HttpClient $http;
    protected AuthService $auth;
    protected Mailer $mailer;
    protected GoogleOAuth $google;
    protected OpenFoodFactsService $off;
    protected ProductService $products;
    protected GameService $games;
    protected AiInsightService $ai;
    protected ShareService $shares;
    protected UploadService $uploads;
    protected string $basePath;

    public function __construct(?HttpClient $http = null)
    {
        $http ??= new CurlHttpClient();
        $this->http = $http;
        $this->mailer = new Mailer(App::$config, App::$log);
        $this->auth = new AuthService(App::$config, App::$log, $this->mailer);
        $this->google = new GoogleOAuth(App::$config, App::$log, $this->http);
        $this->off = new OpenFoodFactsService(App::$config, App::$log, $this->http);
        $this->products = new ProductService();
        $this->games = new GameService($this->products);
        $this->ai = new AiInsightService(App::$config, App::$log, $this->http);
        $this->shares = new ShareService($this->products);
        $this->uploads = new UploadService(App::$config, App::$log);
        $this->basePath = (string)App::$config->get('base_path', '');
    }

    protected function userId(): int
    {
        return Session::userId() ?? 0;
    }

    /** Current user row. */
    protected function currentUser(): array
    {
        $u = $this->auth->findUserById($this->userId());
        if ($u === null) {
            Session::logout();
            Response::redirect($this->basePath, '/login');
        }
        return $u;
    }

    protected function render(string $template, array $data = [], string $layout = 'layouts/base', int $status = 200): never
    {
        $data['flashes'] = Session::pullFlashes();
        $data['isLoggedIn'] = Session::userId() !== null;
        $data['currentUser'] ??= Session::userId() !== null ? $this->currentUser() : null;
        $data['clearReviewDraft'] = Session::get('_clear_review_draft', '');
        $data['clearReviewBefore'] = Session::get('_clear_review_before', 0);
        Session::remove('_clear_review_draft');
        Session::remove('_clear_review_before');
        $data['csrf'] = \SnackQuest\Http\Csrf::field();
        Response::html(View::render($template, $data, $layout), $status);
    }

    protected function redirect(string $target): never
    {
        Response::redirect($this->basePath, $target);
    }
}
