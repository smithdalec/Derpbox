<?php

namespace Smithdalec\DerpboxBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\Security\Core\SecurityContext;
use Smithdalec\DerpboxBundle\Entity\File;
use Smithdalec\DerpboxBundle\Entity\Folder;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Smithdalec\DerpboxBundle\Controller\InitializableControllerInterface;
use Symfony\Component\HttpFoundation\Request;

class DerpboxController extends Controller implements InitializableControllerInterface
{
    protected $user;
    protected $file;
    protected $folder;
    protected $fileRepo;
    protected $folderRepo;
    protected $currentFolder;
    // Entity Manager
    protected $em;

    /**
     * pseudo-constructor
     */
    public function initialize(Request $request)
    {
        $this->file = new File;
        $this->folder = new Folder;
        $this->user = $this->get('security.context')->getToken()->getUser();
        $this->folderRepo = $this->getDoctrine()->getRepository('SmithdalecDerpboxBundle:Folder');
        $this->fileRepo = $this->getDoctrine()->getRepository('SmithdalecDerpboxBundle:File');
        $this->em = $this->getDoctrine()->getEntityManager();
    }

    /**
     * Main page entry point
     * @param  mixed $folder_name   The name of the folder as passed in the URL
     */
    public function indexAction($folder_id = false)
    {
        if ($folder_id) {
            $this->currentFolder = $this->folderRepo->find($folder_id);
        }

        $upload_form = $this->getUploadForm();
        $folder_form = $this->getCreateFolderForm();

        $criteria = array('user' => $this->user->getId());
        if ($this->currentFolder) {
            $criteria['folder'] = $this->currentFolder->getId();
            $folders = array();
        } else {
            $criteria['folder'] = null;
            $folders = $this->folderRepo->findByUser($this->user->getId());
        }
        $files = $this->fileRepo->findBy($criteria);


        $view = 'SmithdalecDerpboxBundle:Derpbox:index.html.twig';
        $args = array(
            'upload_form' => $upload_form->createView(),
            'folder_form' => $folder_form->createView(),
            'files' => $files,
            'folders' => $folders,
            'current_folder' => $this->currentFolder,
        );
        return $this->render($view, $args);
    }

    public function viewPublicFolderAction($folder_id)
    {
        if ($this->folder = $this->folderRepo->find($folder_id)) {
            if (!$this->folder->isPublic()) {
                return $this->forbidden();
            }
            $files = $this->fileRepo->findByFolder($this->folder->getId());

            $view = 'SmithdalecDerpboxBundle:Derpbox:index.html.twig';
            $args = array(
                'upload_form' => false,
                'folder_form' => false,
                'files' => $files,
                'folders' => array(),
                'current_folder' => $this->folder,
            );
            return $this->render($view, $args);
        }

        return $this->redirect($this->generateUrl('derpbox_main'));
    }

    public function loginAction()
    {
        $request = $this->getRequest();
        $session = $request->getSession();

        // get the login error if there is one
        if ($request->attributes->has(SecurityContext::AUTHENTICATION_ERROR)) {
            $error = $request->attributes->get(
                SecurityContext::AUTHENTICATION_ERROR
            );
        } else {
            $error = $session->get(SecurityContext::AUTHENTICATION_ERROR);
            $session->remove(SecurityContext::AUTHENTICATION_ERROR);
        }

        return $this->render(
            'SmithdalecDerpboxBundle:Derpbox:login.html.twig',
            array(
                // last username entered by the user
                'last_username' => $session->get(SecurityContext::LAST_USERNAME),
                'error'         => $error,
            )
        );
    }

    public function addFolderAction()
    {
        $folder_form = $this->getCreateFolderForm();

        if ($this->getRequest()->getMethod() === 'POST') {
            $folder_form->bindRequest($this->getRequest());
            if ($folder_form->isValid()) {
                $this->folder->setUser($this->user->getId());
                $this->folder->setPublic(false);

                $this->em->persist($this->folder);
                $this->em->flush();
            }
        }
        return $this->redirect($this->generateUrl('derpbox_main'));
    }

    public function addFileAction()
    {
        $upload_form = $this->getUploadForm();
        $redirect = $this->generateUrl('derpbox_main');

        if ($this->getRequest()->getMethod() === 'POST') {
            $upload_form->bindRequest($this->getRequest());
            if ($upload_form->isValid()) {
                $this->file->setUser($this->user->getId());
                $this->file->setPublic(false);

                $this->em->persist($this->file);
                $this->em->flush();
                if ($parent_folder_id = $this->file->getFolder()) {
                    $parent_folder = $this->folderRepo->find($parent_folder_id);
                    $args = array('folder_name' => $parent_folder->getName());
                    $redirect = $this->generateUrl('derpbox_view_folder', $args);
                }
            }
            else {var_dump($upload_form->getData());var_dump($upload_form->getErrors()); die();}
        }

        return $this->redirect($redirect);
    }

    public function deleteFileAction($file_id)
    {
        $file = $this->fileRepo->find($file_id);
        $redirect = $this->generateUrl('derpbox_main');

        // Can only delete own files
        if ($file && $this->user->getId() == $file->getUser()) {
            $this->em->remove($file);
            $this->em->flush();
            if ($folder_id = $file->getFolder()) {
                $folder = $this->folderRepo->find($folder_id);
                $args = array('folder_id' => $folder->getId());
                $redirect = $this->generateUrl('derpbox_view_folder', $args);
            }
        }

        return $this->redirect($redirect);
    }

    public function deleteFolderAction($folder_id)
    {
        $folder = $this->folderRepo->find($folder_id);
        $files = $this->fileRepo->findByFolder($folder->getId());
        if (!$folder) {
            throw $this->createNotFoundException('The file does not exist');
        }
        if ($folder->getUser() == $this->user->getId()) {
            $this->em->remove($folder);
            foreach ($files as $file) {
                $this->em->remove($file);
            }
            $this->em->flush();
        }
        return $this->redirect($this->generateUrl('derpbox_main'));
    }

    public function downloadFileAction($file_id)
    {
        $file = $this->fileRepo->find($file_id);
        if (!$file) {
            throw $this->createNotFoundException('The file does not exist');
        }
        $user_authenticated = $this->user instanceof Smithdalec\DerpboxBundle\Entity\User;
        // Whether or not the current user is the owner of the file
        $user_owner = ($user_authenticated && $this->user->getId() == $file->getUser());
        // If the enclosing folder is public
        $public_folder = false;
        if ($file->getFolder()) {
            $folder = $this->folderRepo->find($file->getFolder());
        } else {
            $folder = false;
        }
        if ($folder && $folder->isPublic()) {
            $public_folder = true;
        }
        if ($file->isPublic() || $user_owner || $public_folder) {
            return $this->download($file);
        } else {
            return $this->forbidden();
        }
    }

    public function downloadPublicFileAction($file_id)
    {
        return $this->downloadFileAction($file_id);
    }

    public function makeFilePublicAction($file_id)
    {
        return $this->changeFileVisibility($file_id, true);
    }

    public function makeFilePrivateAction($file_id)
    {
        return $this->changeFileVisibility($file_id, false);
    }

    protected function changeFileVisibility($file_id, $is_public)
    {
        $redirect = $this->generateUrl('derpbox_main');
        $file = $this->fileRepo->find($file_id);
        if (!$file) {
            throw $this->createNotFoundException('The file does not exist');
        }
        if ($this->user->getId() == $file->getUser()) {
            $file->setPublic($is_public);
            $this->em->flush();
            if ($folder_id = $file->getFolder()) {
                $folder = $this->folderRepo->find($folder_id);
                $args = array('folder_id' => $folder->getId());
                $redirect = $this->generateUrl('derpbox_view_folder', $args);
            }
            return $this->redirect($redirect);
        }

        return $this->forbidden();
    }

    public function makeFolderPublicAction($folder_id)
    {
        return $this->changeFolderVisibility($folder_id, true);
    }

    public function makeFolderPrivateAction($folder_id)
    {
        return $this->changeFolderVisibility($folder_id, false);
    }

    protected function changeFolderVisibility($folder_id, $is_public)
    {
        $redirect = $this->generateUrl('derpbox_main');
        $folder = $this->folderRepo->find($folder_id);
        if (!$folder) {
            throw $this->createNotFoundException('The folder does not exist');
        }
        if ($this->user->getId() == $folder->getUser()) {
            $folder->setPublic($is_public);
            // set files within the folder public
            $criteria = array(
                'user' => $this->user->getId(),
                'folder' => $folder->getId(),
            );
            $files = $this->fileRepo->findBy($criteria);
            foreach ($files as $file) {
                $file->setPublic($is_public);
            }
            $this->em->flush();
            return $this->redirect($redirect);
        }

        return $this->forbidden();
    }

    public function download($file)
    {
        $response = new Response;
        $content = file_get_contents($file->getWebPath(), 'rb');
        $d = $response->headers->makeDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $file->getName());
        $response->headers->set('Content-Disposition', $d);
        $response->headers->set('Content-Type', 'mime/type');
        $response->setContent($content);
        return $response;
    }

    public function forbidden()
    {
        $response = new Response;
        $response->setStatusCode('403');
        return $response;
    }

    protected function getUploadForm()
    {
        $file_form_builder = $this->createFormBuilder($this->file)->add('file');
        $criteria = array();
        if ($this->currentFolder) {
            $criteria['data'] = $this->currentFolder->getId();
        }
        $file_form_builder->add('folder', 'hidden', $criteria);

        return $file_form_builder->getForm();
    }

    protected function getCreateFolderForm()
    {
        return $this->createFormBuilder($this->folder)
            ->add('name')
            ->getForm();
    }

}
