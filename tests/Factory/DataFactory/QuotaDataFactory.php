<?php
namespace Factory\DataFactory;

class QuotaDataFactory implements \Tests\Factory\DataFactory
{

  private $type = 'spio';
  private $qdata;

  public function __construct($type = 'spio')
  {
      $this->type = $type;
      $this->setData($type);
  }

  private function setData($type)
  {
    if ($type == 'spio')
     $this->qdata = $this->createQuotaDataObject();
    elseif ($type == 'remote') {
      $this->qdata = $this->createRemoteQuotaArray();
    }

  }

  public function createQuotaDataObject()
  {
        $quota = (object) [
            'unlimited' => true,
            'monthly' => (object) [
              'text' =>  sprintf(__('%s/month', 'shortpixel-image-optimiser'), 100),
              'total' =>  100,
              'consumed' => 10,
              'remaining' => 90,
              'renew' => 30,
            ],
            'onetime' => (object) [
              'text' => '100',
              'total' => 100,
              'consumed' => 10,
              'remaining' => 90,
            ],
        ];

        $quota->total = (object) [
            'total' =>  200,
            'consumed'  => 20,
            'remaining' => 180,
        ];

        return $quota;
  }

  public function createRemoteQuotaArray()
  {

  }

  public function reset()
  {
     $this->setData($this->type);
  }

  public function set($name, $value)
  {
      if (is_object($this->qdata))
      {
         if (property_exists($this->qdata, $name))
         {
            $this->qdata->$name = $value;
         }
         else {
           throw new Exception('SetData - Not existing');
         }
      }
      else { // @todo Implement return array for the remote Quota controller tests.
          throw new Exception('SetData - Not object');
      }


      return $this;
  }

  public function returnData($format)
  {
     return clone $this->qdata;
  }


}
