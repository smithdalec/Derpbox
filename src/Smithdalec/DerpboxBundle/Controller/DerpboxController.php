<?php

namespace Smithdalec\DerpboxBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\Security\Core\SecurityContext;
use Smithdalec\DerpboxBundle\Entity\User;
use Smithdalec\DerpboxBundle\Entity\User as DerpboxUser;
use Smithdalec\DerpboxBundle\Entity\Folder;
use Smithdalec\DerpboxBundle\Entity\File;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Smithdalec\DerpboxBundle\Controller\InitializableControllerInterface;
use Symfony\Component\HttpFoundation\Request;

class DerpboxController extends Controller implements InitializableControllerInterface
{
    /**
     * @var Smithdalec\DerpboxBundle\Entity\User
     */
    protected $user;

    /**
     * @var Smithdalec\DerpboxBundle\Entity\File
     */
    protected $file;

    /**
     * @var Smithdalec\DerpboxBundle\Entity\Folder
     */
    protected $folder;

    /**
     * Doctrine Repository for the File Entity
     * @var
     */
    protected $fileRepo;

    /**
     * Doctrine Repository for the Folder Entity
     * @var
     */
    protected $folderRepo;

    /**
     * The Entity of the folder currently being viewed
     * @var
     */
    protected $currentFolder;

    /**
     * Universal Doctrine Entity Manager
     * @var
     */
    protected $em;

    /**
     * Pseudo-constructor used by InitializableControllerInterface
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
     * Route Function for home page
     * @param  mixed $folder_name   The ID of the folder as passed in the URL
     */
    public function indexAction($folder_id = false)
    {
        if ($folder_id) {
            $this->currentFolder = $this->folderRepo->find($folder_id);
        }

        $upload_form = $this->getUploadForm();
        $folder_form = $this->getCreateFolderForm();

        $criteria = array('user' => $this->user->getId());
        // If we're viewing a folder (as opposed to the root of the files)
        if ($this->currentFolder) {
            $criteria['folder'] = $this->currentFolder->getId();
            $folders = array();
        } else {
            $criteria['folder'] = null;
            $folders = $this->folderRepo->findByUser($this->user->getId());
        }
        // Get all files within the specified folder (if any)
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

    /**
     * Route handler for /public/folder/{folder_id}
     *
     * @param  int $folder_id ID of the folder to view
     */
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

    /**
     * Route handler for the login page/form
     */
    public function loginAction()
    {
        $request = $this->getRequest();
        $session = $request->getSession();

        // Get the login error if there are any
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

    /**
     * Route handler for /folder/add
     */
    public function addFolderAction()
    {
        $folder_form = $this->getCreateFolderForm();

        // If a form was submitted
        if ($this->getRequest()->getMethod() === 'POST') {
            // Map form values to Form entity properties
            $folder_form->bindRequest($this->getRequest());
            if ($folder_form->isValid()) {
                // Passed all assertions/validation constraints
                $this->folder->setUser($this->user->getId());
                $this->folder->setPublic(false);

                // Prepare/combine queries
                $this->em->persist($this->folder);
                // Execute queries
                $this->em->flush();
            }
        }
        return $this->redirect($this->generateUrl('derpbox_main'));
    }

    /**
     * Route handler for /file/add
     */
    public function addFileAction()
    {
        $upload_form = $this->getUploadForm();
        // Index URL
        $redirect = $this->generateUrl('derpbox_main');

        // If form was submitted
        if ($this->getRequest()->getMethod() === 'POST') {
            // Map entity/form
            $upload_form->bindRequest($this->getRequest());
            // If data passed validation
            if ($upload_form->isValid()) {
                $this->file->setUser($this->user->getId());
                $this->file->setPublic(false);

                // Execute the queries
                $this->em->persist($this->file);
                $this->em->flush();

                // If the user added a file within a folder, stay in that folder
                if ($parent_folder_id = $this->file->getFolder()) {
                    $parent_folder = $this->folderRepo->find($parent_folder_id);
                    $args = array('folder_name' => $parent_folder->getName());
                    $redirect = $this->generateUrl('derpbox_view_folder', $args);
                }
            }
        }

        return $this->redirect($redirect);
    }

    /**
     * Route handler for /file/{file_id}/delete
     * @param  int $file_id The ID of the file to delete
     */
    public function deleteFileAction($file_id)
    {
        $file = $this->fileRepo->find($file_id);
        $redirect = $this->generateUrl('derpbox_main');

        // Can only delete own files (that exist)
        if ($file && $this->user->getId() == $file->getUser()) {
            $this->em->remove($file);
            $this->em->flush();
            // Forward to the folder the user was viewing
            if ($folder_id = $file->getFolder()) {
                $folder = $this->folderRepo->find($folder_id);
                $args = array('folder_id' => $folder->getId());
                $redirect = $this->generateUrl('derpbox_view_folder', $args);
            }
        }

        return $this->redirect($redirect);
    }

    /**
     * Route handler for /folder/{folder_id}/delete
     * @param  int $folder_id ID of the folder to delete
     */
    public function deleteFolderAction($folder_id)
    {
        $folder = $this->folderRepo->find($folder_id);
        $files = $this->fileRepo->findByFolder($folder->getId());
        if (!$folder) {
            throw $this->createNotFoundException('The file does not exist');
        }
        if ($folder->getUser() == $this->user->getId()) {
            // Delete the entity record (short-term)
            $this->em->remove($folder);
            foreach ($files as $file) {
                $this->em->remove($file);
            }
            // Persist it to the db
            $this->em->flush();
        }
        return $this->redirect($this->generateUrl('derpbox_main'));
    }

    /**
     * Route handler for /file/{file_id}/download
     * @param  int $file_id The ID of the file to download
     */
    public function downloadFileAction($file_id)
    {
        $file = $this->fileRepo->find($file_id);
        if (!$file) {
            throw $this->createNotFoundException('The file does not exist');
        }
        // If the use is authenticated anonymously (not logged in), the expected
        // User object is actually a string or NULL, so check to make sure it's
        // of the proper class
        $user_authenticated = ($this->user instanceof DerpboxUser);
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

    /**
     * Route Handler for /public/file/{file_id}/download
     * Public wrapper around downloadFileAction()
     */
    public function downloadPublicFileAction($file_id)
    {
        return $this->downloadFileAction($file_id);
    }

    /**
     * Route handler for /file/{file_id}/make-public
     */
    public function makeFilePublicAction($file_id)
    {
        return $this->changeFileVisibility($file_id, true);
    }

    /**
     * Route handler for /file/{file_id}/make-private
     */
    public function makeFilePrivateAction($file_id)
    {
        return $this->changeFileVisibility($file_id, false);
    }

    /**
     * Changes a public file to private, or vice versa
     * @param  int $file_id   The ID of the file to change
     * @param  boolean $is_public Whether or not the file should be changed to public visibility
     */
    protected function changeFileVisibility($file_id, $is_public)
    {
        // Index URL
        $redirect = $this->generateUrl('derpbox_main');
        $file = $this->fileRepo->find($file_id);
        if (!$file) {
            throw $this->createNotFoundException('The file does not exist');
        }
        // Should only be able to modify own files
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

    /**
     * Route handler for /folder/{folder_id}/make-public
     */
    public function makeFolderPublicAction($folder_id)
    {
        return $this->changeFolderVisibility($folder_id, true);
    }

    /**
     * Route handler for /folder/{folder_id}/make-private
     */
    public function makeFolderPrivateAction($folder_id)
    {
        return $this->changeFolderVisibility($folder_id, false);
    }

    /**
     * Make a public folder private, or vice versa
     * @param  int $folder_id The ID of teh folder
     * @param  boolean $is_public Whether or not the file shoudl end up being public
     */
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

    /**
     * Returns the contents of the file to download
     * @param  Smithdalec\DerpboxBundle\Entity/File $file
     */
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

    /**
     * Return a 403 Response for unauthorized requests
     */
    public function forbidden()
    {
        $response = new Response;
        $response->setStatusCode('403');
        return $response;
    }

    /**
     * Builds the File Upload Form
     */
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

    /**
     * Builds the Add Folder form
     */
    protected function getCreateFolderForm()
    {
        return $this->createFormBuilder($this->folder)
            ->add('name')
            ->getForm();
    }

}
