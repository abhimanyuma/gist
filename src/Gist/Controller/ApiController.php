<?php

namespace Gist\Controller;

use Symfony\Component\HttpFoundation\Request;
use Gist\Model\Gist;
use Symfony\Component\HttpFoundation\JsonResponse;
use Gist\Form\ApiCreateGistForm;
use Gist\Model\GistQuery;
use Gist\Form\ApiUpdateGistForm;
use GitWrapper\GitException;
use Gist\Model\UserQuery;

/**
 * Class ApiController.
 *
 * @author Simon Vieille <simon@deblan.fr>
 */
class ApiController extends Controller
{
    /**
     * Lists gists.
     *
     * @param Request $request
     * @param string  $apiKey
     *
     * @return JsonResponse
     */
    public function listAction(Request $request, $apiKey)
    {
        $app = $this->getApp();

        if (false === $app['settings']['api']['enabled']) {
            return new Response('', 403);
        }

        if (false === $this->isValidApiKey($apiKey, true)) {
            return $this->invalidApiKeyResponse();
        }

        if (false === $request->isMethod('get')) {
            return $this->invalidMethodResponse('GET method is required.');
        }

        $gists = GistQuery::create()->find();
        $data = array();

        foreach ($gists as $gist) {
            try {
                $history = $app['gist']->getHistory($gist);

                $value = $gist->toArray();
                $value['url'] = $request->getSchemeAndHttpHost().$app['url_generator']->generate(
                    'view',
                    array(
                        'gist' => $gist->getFile(),
                        'commit' => array_pop($history)['commit'],
                    )
                );

                $data[] = $value;
            } catch (GitException $e) {
            }
        }

        return new JsonResponse($data);
    }

    /**
     * Creates a gist.
     *
     * @param Request $request
     * @param string  $apiKey
     *
     * @return JsonResponse
     */
    public function createAction(Request $request, $apiKey)
    {
        $app = $this->getApp();

        if (false === $app['settings']['api']['enabled']) {
            return new Response('', 403);
        }

        if (false === $this->isValidApiKey($apiKey)) {
            return $this->invalidApiKeyResponse();
        }

        if (false === $request->isMethod('post')) {
            return $this->invalidMethodResponse('POST method is required.');
        }

        $form = new ApiCreateGistForm(
            $app['form.factory'],
            $app['translator'],
            [],
            ['csrf_protection' => false]
        );

        $form = $form->build()->getForm();

        $form->submit($request);

        if ($form->isValid()) {
            $gist = $app['gist']->create(new Gist(), $form->getData());
            $gist->setCipher(false)->save();

            $history = $app['gist']->getHistory($gist);

            $data = $gist->toArray();
            $data['url'] = $request->getSchemeAndHttpHost().$app['url_generator']->generate(
                'view',
                array(
                    'gist' => $gist->getFile(),
                    'commit' => array_pop($history)['commit'],
                )
            );

            return new JsonResponse($data);
        }

        return $this->invalidRequestResponse('Invalid field(s)');
    }

    /**
     * Updates a gist.
     *
     * @param Request $request
     * @param string  $gist
     * @param string  $apiKey
     *
     * @return JsonResponse
     */
    public function updateAction(Request $request, $gist, $apiKey)
    {
        $app = $this->getApp();

        if (false === $app['settings']['api']['enabled']) {
            return new Response('', 403);
        }

        if (false === $this->isValidApiKey($apiKey)) {
            return $this->invalidApiKeyResponse();
        }

        if (false === $request->isMethod('post')) {
            return $this->invalidMethodResponse('POST method is required.');
        }

        $gist = GistQuery::create()
            ->filterByCipher(false)
            ->filterById((int) $gist)
            ->_or()
            ->filterByFile($gist)
            ->findOne();

        if (!$gist) {
            return $this->invalidRequestResponse('Invalid Gist');
        }

        $form = new ApiUpdateGistForm(
            $app['form.factory'],
            $app['translator'],
            [],
            ['csrf_protection' => false]
        );

        $form = $form->build()->getForm();

        $form->submit($request);

        if ($form->isValid()) {
            $gist = $app['gist']->commit($gist, $form->getData());

            $history = $app['gist']->getHistory($gist);

            $data = $gist->toArray();
            $data['url'] = $request->getSchemeAndHttpHost().$app['url_generator']->generate(
                'view',
                array(
                    'gist' => $gist->getFile(),
                    'commit' => array_pop($history)['commit'],
                )
            );

            return new JsonResponse($data);
        }

        return $this->invalidRequestResponse('Invalid field(s)');
    }

    /**
     * Builds an invalid api key response.
     *
     * @param mixed $message
     *
     * @return JsonResponse
     */
    protected function invalidApiKeyResponse()
    {
        $data = [
            'error' => ' Unauthorized',
            'message' => 'Invalid API KEY',
        ];

        return new JsonResponse($data, 401);
    }

    /**
     * Builds an invalid method response.
     *
     * @param mixed $message
     *
     * @return JsonResponse
     */
    protected function invalidMethodResponse($message = null)
    {
        $data = [
            'error' => 'Method Not Allowed',
            'message' => $message,
        ];

        return new JsonResponse($data, 405);
    }

    /**
     * Builds an invalid request response.
     *
     * @param mixed $message
     *
     * @return JsonResponse
     */
    protected function invalidRequestResponse($message = null)
    {
        $data = [
            'error' => 'Bad Request',
            'message' => $message,
        ];

        return new JsonResponse($data, 400);
    }

    protected function isValidApiKey($apiKey, $required = false)
    {
        if (empty($apiKey)) {
            return !$required;
        }

        return UserQuery::create()
            ->filterByApiKey($apiKey)
            ->count() === 1;
    }
}
