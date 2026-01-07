<?php

declare(strict_types=1);

use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use Tattali\PresignedUrl\Bridge\Symfony\Controller\ServeController;

return static function (RoutingConfigurator $routes): void {
    $routes->add('presigned_url_serve', '/storage/serve/{bucket}/{path}')
        ->controller(ServeController::class)
        ->requirements(['path' => '.+'])
        ->methods(['GET', 'HEAD']);
};
