<?php

declare(strict_types=1);

namespace App\Interface\Http;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

final class LoginController extends AbstractController
{
    #[Route('/login', name: 'owner_login', methods: ['GET', 'POST'])]
    public function login(AuthenticationUtils $authentication): Response
    {
        return $this->render('panel/login.html.twig', ['error' => $authentication->getLastAuthenticationError()]);
    }
    #[Route('/logout', name: 'owner_logout', methods: ['POST'])]
    public function logout(): never
    {
        throw new \LogicException('Handled by the security firewall.');
    }
}
