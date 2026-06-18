<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class HomeController extends AbstractController
{
    #[Route('/', name: 'app_root')]
    public function root(Request $request): Response
    {
        $locale = $request->getSession()->get('_locale', 'en');

        if ($this->getUser()) {
            return $this->redirectToRoute('demo_home', ['_locale' => $locale]);
        }

        return $this->redirectToRoute('app_welcome', ['_locale' => $locale]);
    }

    #[Route('/{_locale}', name: 'app_welcome', requirements: ['_locale' => 'en|es'])]
    public function welcome(string $_locale): Response
    {
        if ($this->getUser()) {
            return $this->redirectToRoute('demo_home', ['_locale' => $_locale]);
        }

        return $this->render('demo/welcome.html.twig');
    }

    #[Route('/{_locale}/home', name: 'demo_home', requirements: ['_locale' => 'en|es'])]
    public function home(): Response
    {
        $this->denyAccessUnlessGranted('ROLE_USER');

        return $this->render('demo/home.html.twig', [
            'user' => $this->getUser(),
        ]);
    }
}
