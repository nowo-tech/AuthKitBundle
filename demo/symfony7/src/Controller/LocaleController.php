<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Translation\LocaleSwitcher;

final class LocaleController extends AbstractController
{
    #[Route('/locale/{_locale}', name: 'app_set_locale', requirements: ['_locale' => 'en|es'])]
    public function setLocale(string $_locale, Request $request, LocaleSwitcher $localeSwitcher): Response
    {
        $request->getSession()->set('_locale', $_locale);
        $localeSwitcher->setLocale($_locale);

        $referer = $request->headers->get('referer');
        if ($referer !== null && str_starts_with($referer, $request->getSchemeAndHttpHost())) {
            return $this->redirect($referer);
        }

        return $this->redirectToRoute('nowo_auth_kit_login');
    }
}
