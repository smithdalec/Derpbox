<?php
namespace Smithdalec\DerpboxBundle\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\SecurityContextInterface;

interface InitializableControllerInterface
{
    public function initialize(Request $request);
}