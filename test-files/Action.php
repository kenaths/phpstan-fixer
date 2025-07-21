<?php

namespace EcommerceGeeks\LaravelInertiaTables\Components;

/**
 * @description A generic action component that can be used to create a button that performs an action
 */
class Action extends Link
{
    protected string $type = 'action';

    /**
     * @description Set the route name for the action
     */
    public function route(string $routeName): self
    {
        $this->routeName = $routeName;

        return $this;
    }

    /**
     * @description Set the content for the action
     */
    public function content(mixed $content): self
    {
        $this->content = $content;

        return $this;
    }

    /**
     * @description Set the parameters for the action
     */
    public function parameter(string $parameter): self
    {
        $this->setParameter($parameter);

        return $this;
    }

    /**
     * @description Use inertia for the action
     */
    public function useInertia(bool $useInertia = true): self
    {
        $this->useInertia = $useInertia;

        return $this;
    }

    /**
     * @description Set the confirmation message for the action
     */
    public function confirmation(?string $confirmation): self
    {
        $this->confirmation = $confirmation;

        return $this;
    }

    /**
     * @description Set the maximum length for the action
     */
    public function maxLength(?int $maxLength): self
    {
        $this->maxLength = $maxLength;

        return $this;
    }

    /**
     * @description Open the action in a new tab
     */
    public function newTab(bool $newTab = true): self
    {
        $this->newTab = $newTab;

        return $this;
    }

    /**
     * @description Set the method for the action
     */
    public function method(string $method): self
    {
        $this->setMethod($method);

        return $this;
    }

    /**
     * @description Set the icon for the action
     */
    public function icon(string $iconName): self
    {
        $this->setIcon($iconName);

        return $this;
    }

    /**
     * @description Set the variant for the action
     */
    public function variant(string $variant): self
    {
        $this->setVariant($variant);

        return $this;
    }

    /**
     * @description Set the class mapping for the action
     * @param array<mixed> $mapping
 */
    public function classMapping(array $mapping): self
    {
        $this->setClassMapping($mapping);

        return $this;
    }

    protected function getClasses(): string
    {
        $classes = parent::getClasses();
        $classes .= ' btn btn-sm';

        return $classes;
    }

    /**
     * @description Create a new action component
     * @param array<mixed> $parameter
 */
    public static function make(
        string $routeName,
        mixed $content, array $parameter = [],
        bool $useInertia = true,
        ?string $confirmation = null,
        ?int $maxLength = 100,
        bool $newTab = false
    ): Action {
        return new Action($routeName, $content, $parameter, $useInertia, $confirmation, $maxLength, $newTab);
    }
}
