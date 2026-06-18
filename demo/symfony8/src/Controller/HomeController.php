<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class HomeController extends AbstractController
{
    #[Route('/', name: 'app_root')]
    public function root(): Response
    {
        if ($this->getUser()) {
            return $this->redirectToRoute('demo_home');
        }

        return $this->redirectToRoute('nowo_auth_kit_login');
    }

    #[Route('/home', name: 'demo_home')]
    public function home(): Response
    {
        $this->denyAccessUnlessGranted('ROLE_USER');

        return $this->render('demo/home.html.twig', [
            'user' => $this->getUser(),
        ]);
    }
}
