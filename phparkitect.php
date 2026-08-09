<?php

declare(strict_types=1);

use Arkitect\ClassSet;
use Arkitect\CLI\Config;
use Arkitect\Expression\ForClasses\Extend;
use Arkitect\Expression\ForClasses\HaveAttribute;
use Arkitect\Expression\ForClasses\HaveNameMatching;
use Arkitect\Expression\ForClasses\NotDependsOnTheseNamespaces;
use Arkitect\Expression\ForClasses\ResideInOneOfTheseNamespaces;
use Arkitect\Rules\Rule;

return static function (Config $config): void {
    $classes = ClassSet::fromDir(__DIR__.'/src');

    $rules = [
        Rule::allClasses()
            ->that(new ResideInOneOfTheseNamespaces('Gsoi\Skeleton\Domain'))
            ->should(new NotDependsOnTheseNamespaces([
                'Gsoi\Skeleton\Application',
                'Gsoi\Skeleton\Infrastructure',
                'Gsoi\Skeleton\UI',
                'Doctrine\ORM\EntityManagerInterface',
            ]))
            ->because('the domain must remain independent from outer layers'),
        Rule::allClasses()
            ->that(new ResideInOneOfTheseNamespaces('Gsoi\Skeleton\Application'))
            ->should(new NotDependsOnTheseNamespaces([
                'Gsoi\Skeleton\Infrastructure',
                'Gsoi\Skeleton\UI',
                'Doctrine\ORM\EntityManagerInterface',
            ]))
            ->because('application handlers must use domain ports'),
        Rule::allClasses()
            ->that(new HaveNameMatching('*Handler'))
            ->should(new HaveAttribute('Symfony\Component\Messenger\Attribute\AsMessageHandler'))
            ->because('application handlers must be registered explicitly'),
        Rule::allClasses()
            ->that(new HaveNameMatching('*Exception'))
            ->andThat(new ResideInOneOfTheseNamespaces('Gsoi\Skeleton\Domain'))
            ->should(new Extend('Gsoi\Skeleton\Domain\DomainException'))
            ->because('domain exceptions share a stable base type'),
    ];

    $config->add($classes, ...$rules);
};
