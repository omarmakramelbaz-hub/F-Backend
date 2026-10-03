<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Pagination\Paginator;
use Carbon\Carbon;
use App\Interfaces\Api\UserAuthRepositoryInterface;
use App\Repositories\Api\UserAuthRepository;
use App\Interfaces\Api\AuthRepositoryInterface;
use App\Repositories\Api\AuthRepository;

use App\Interfaces\UserRepositoryInterface;
use App\Repositories\UserRepository;
use App\Interfaces\AboutRepositoryInterface;
use App\Repositories\AboutRepository;

use App\Interfaces\SlotRepositoryInterface;
use App\Repositories\SlotRepository;

use App\Interfaces\QuestionAnswerRepositoryInterface;
use App\Repositories\QuestionAnswerRepository;
use App\Interfaces\ResturantProductRepositoryInterface;
use App\Repositories\ResturantProductRepository;

use App\Interfaces\ResturantRepositoryInterface;
use App\Repositories\ResturantRepository;
use App\Interfaces\CategoryTypeRepositoryInterface;
use App\Repositories\CategoryTypeRepository;

use App\Interfaces\CategoryRepositoryInterface;
use App\Repositories\CategoryRepository;

use App\Interfaces\ServiceRepositoryInterface;
use App\Repositories\ServiceRepository;

use App\Interfaces\ProductRepositoryInterface;
use App\Repositories\ProductRepository;

use App\Interfaces\TicketRepositoryInterface;
use App\Repositories\TicketRepository;
use App\Interfaces\BannerRepositoryInterface;
use App\Repositories\BannerRepository;

use App\Interfaces\FeatureRepositoryInterface;
use App\Repositories\FeatureRepository;
use App\Models\User;
use App\Models\Order;
use App\Observers\UserObserver;
use App\Observers\OrderCompetitionObserver;
use App\Observers\OrderMetaConversionObserver;
use App\Observers\OrderBoardClockObserver;
use App\Observers\NotificationObserver;
use Illuminate\Notifications\DatabaseNotification;

class AppServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->app->bind(UserAuthRepositoryInterface::class, UserAuthRepository::class);
        $this->app->bind(AuthRepositoryInterface::class, AuthRepository::class);

        $this->app->bind(UserRepositoryInterface::class, UserRepository::class);
        $this->app->bind(AboutRepositoryInterface::class, AboutRepository::class);
        $this->app->bind(SlotRepositoryInterface::class, SlotRepository::class);
        $this->app->bind(ResturantRepositoryInterface::class, ResturantRepository::class);
        $this->app->bind(ResturantProductRepositoryInterface::class, ResturantProductRepository::class);
        $this->app->bind(TicketRepositoryInterface::class, TicketRepository::class);
        $this->app->bind(CategoryTypeRepositoryInterface::class, CategoryTypeRepository::class);
        $this->app->bind(CategoryRepositoryInterface::class, CategoryRepository::class);
        $this->app->bind(ServiceRepositoryInterface::class, ServiceRepository::class);
        $this->app->bind(ProductRepositoryInterface::class, ProductRepository::class);
        $this->app->bind(FeatureRepositoryInterface::class, FeatureRepository::class);
        $this->app->bind(BannerRepositoryInterface::class, BannerRepository::class);
        $this->app->bind(QuestionAnswerRepositoryInterface::class, QuestionAnswerRepository::class);
    }

    public function boot()
    {
        Paginator::useBootstrap();
        User::observe(UserObserver::class);
        Order::observe(OrderCompetitionObserver::class);
        Order::observe(OrderMetaConversionObserver::class);
        Order::observe(OrderBoardClockObserver::class);
        DatabaseNotification::observe(NotificationObserver::class);

        view()->composer('*', function ($view)
        {
            // $site_trademarks = \App\Models\Trademark::latest()->get();
            // $view->with(compact('site_trademarks'));
        });
    }
}
