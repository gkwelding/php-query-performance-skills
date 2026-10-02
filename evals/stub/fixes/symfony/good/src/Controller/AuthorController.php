<?php

namespace App\Controller;

use App\Repository\AuthorRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class AuthorController extends AbstractController
{
    #[Route('/authors', name: 'author_index', methods: ['GET'])]
    public function index(AuthorRepository $authors): Response
    {
        return $this->render('authors/index.html.twig', [
            'authors' => $authors->findAllWithPublisherAndBooks(),
        ]);
    }
}
