<?php


namespace Civi\OAuth\Provider;


class OpenStreetMapUser implements \League\OAuth2\Client\Provider\ResourceOwnerInterface {

  /**
   * @var array
   */
  protected $response;

  /**
   * @param array $response
   */
  public function __construct(array $response)
  {
    $this->response = $response['user'];
  }

  /**
   * @inheritDoc
   */
  public function getId() {
    return $this->response['id']();
  }

  /**
   * Get username.
   *
   * @return string
   */
  public function getName()
  {
    return $this->response['display_name'];
  }

  /**
   * @inheritDoc
   */
  public function toArray() {
    return $this->response;
  }

}