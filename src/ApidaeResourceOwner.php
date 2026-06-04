<?php

namespace ApidaeTourisme\ApidaeBundle;

use HWI\Bundle\OAuthBundle\OAuth\Response\UserResponseInterface;
use Symfony\Component\OptionsResolver\Options;
use Symfony\Contracts\HttpClient\ResponseInterface;
use HWI\Bundle\OAuthBundle\Security\OAuthErrorHandler;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\HttpClient\Exception\JsonException;
use HWI\Bundle\OAuthBundle\Security\Helper\NonceGenerator;
use Symfony\Component\HttpFoundation\Request as HttpRequest;
use HWI\Bundle\OAuthBundle\OAuth\Exception\HttpTransportException;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use HWI\Bundle\OAuthBundle\Security\Core\Authentication\Token\OAuthToken;
use HWI\Bundle\OAuthBundle\OAuth\ResourceOwner\GenericOAuth2ResourceOwner;

final class ApidaeResourceOwner extends GenericOAuth2ResourceOwner
{
    /**
     * {@inheritdoc}
     */
    public function getUserInformation(array $accessToken, array $extraParameters = []): UserResponseInterface
    {
        if ($this->options['use_bearer_authorization']) {
            $content = $this->httpRequest(
                $this->normalizeUrl($this->options['infos_url'], $extraParameters),
                null,
                ['Authorization' => 'Bearer '.$accessToken['access_token']]
            );
        } else {
            $content = $this->doGetUserInformationRequest(
                $this->normalizeUrl(
                    $this->options['infos_url'],
                    array_merge([$this->options['attr_name'] => $accessToken['access_token']], $extraParameters)
                )
            );
        }

        try {
            $response = $this->getUserResponse();
            $response->setData($content->toArray(false));
            $response->setResourceOwner($this);
            $response->setOAuthToken(new OAuthToken($accessToken));

            return $response;
        } catch (TransportExceptionInterface|JsonException $e) {
            throw new HttpTransportException('Error while sending HTTP request', $this->getName(), $e->getCode(), $e);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function getAuthorizationUrl($redirectUri, array $extraParameters = []): string
    {
        if ($this->options['csrf']) {
            $this->handleCsrfToken();
        }

        $parameters = array_merge([
            'response_type' => 'code',
            'client_id' => $this->options['client_id'],
            'scope' => $this->options['scope'],
            'state' => $this->state->encode(),
            'redirect_uri' => $redirectUri,
        ], $extraParameters);

        return $this->normalizeUrl($this->options['authorization_url'], $parameters);
    }

    /**
     * {@inheritdoc}
     */
    public function getAccessToken(HttpRequest $request, $redirectUri, array $extraParameters = []): array
    {
        //dump(['method' => __METHOD__,'request' => $request, 'redirectUri' => $redirectUri, 'extraParameters' => $extraParameters]) ;

        OAuthErrorHandler::handleOAuthError($request);

        $parameters = array_merge([
            'code' => $request->query->get('code'),
            'grant_type' => 'authorization_code',
            'redirect_uri' => $redirectUri,
        ], $extraParameters);

        $response = $this->doGetTokenRequest($this->options['access_token_url'], $parameters);
        $response = $this->getResponseContentFromXml($response) ;

        //dump(['method' => __METHOD__, 'response' => $response]) ;

        $this->validateResponseContent($response);

        return $response;
    }

    /**
     * {@inheritdoc}
     */
    public function refreshAccessToken($refreshToken, array $extraParameters = [])
    {
        $parameters = array_merge([
            'refresh_token' => $refreshToken,
            'grant_type' => 'refresh_token',
        ], $extraParameters);

        $response = $this->doGetTokenRequest($this->options['access_token_url'], $parameters);
        $response = $this->getResponseContentFromXml($response);

        $this->validateResponseContent($response);

        return $response;
    }

    /**
     * {@inheritdoc}
     */
    protected function doGetUserInformationRequest($url, array $parameters = []): ResponseInterface
    {
        return $this->httpRequest($url, http_build_query($parameters, '', '&'));
    }

    /**
     * @param mixed $response the 'parsed' content based on the response headers
     *
     * @throws AuthenticationException If an OAuth error occurred or no access token is found
     */
    protected function validateResponseContent($response)
    {
        if (isset($response['error_description'])) {
            throw new AuthenticationException(sprintf('OAuth error: "%s"', $response['error_description']));
        }

        if (isset($response['error'])) {
            throw new AuthenticationException(sprintf('OAuth error: "%s"', $response['error']['message'] ?? $response['error']));
        }

        if (!isset($response['access_token'])) {
            throw new AuthenticationException('Not a valid access token.');
        }
    }

    /**
     * {@inheritdoc}
     */
    protected function configureOptions(OptionsResolver $resolver)
    {
        parent::configureOptions($resolver);

        $resolver->setDefaults([
            'attr_name' => 'access_token',
            'use_commas_in_scope' => false,
            'use_bearer_authorization' => true,
            'use_authorization_to_get_token' => true,
            'refresh_on_expire' => false,
        ]);

        $resolver->setDefined('revoke_token_url');
        $resolver->setAllowedValues('refresh_on_expire', [true, false]);

        // Unfortunately some resource owners break the spec by using commas instead
        // of spaces to separate scopes (Disqus, Facebook, Github, Vkontante)
        $scopeNormalizer = function (Options $options, $value) {
            if (!$value) {
                return null;
            }

            if (!$options['use_commas_in_scope']) {
                return $value;
            }

            return str_replace(',', ' ', $value);
        };

        $resolver->setNormalizer('scope', $scopeNormalizer);
    }

    /**
     * {@inheritdoc}
     */
    protected function httpRequest($url, $content = null, array $headers = [], $method = null): ResponseInterface
    {
        $headers += ['Content-Type' => 'application/x-www-form-urlencoded'];

        return parent::httpRequest($url, $content, $headers, $method);
    }

    private function handleCsrfToken(): void
    {
        if (null === $this->state->getCsrfToken()) {
            $this->state->setCsrfToken(NonceGenerator::generate());
        }

        $this->storage->save($this, $this->state->getCsrfToken(), 'csrf_state');
    }

    /**
     * @see https://stackoverflow.com/questions/4554233/how-check-if-a-string-is-a-valid-xml-with-out-displaying-a-warning-in-php
     */
    protected function getResponseContentFromXml(ResponseInterface $response)
    {
        $rawResponse = $response->getContent(false) ;
        libxml_use_internal_errors(true);

        $doc = simplexml_load_string($rawResponse);

        if (!$doc) {
            $errors = libxml_get_errors();
            dd($errors);
            libxml_clear_errors();
        }

        return (array)$doc;
    }
}
