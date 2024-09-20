<?php

namespace Tests\Unit\GraphQL;

use Appwrite\GraphQL\Types\Mapper;
use Appwrite\Utopia\Response;
use Appwrite\Utopia\Response\Models;
use PHPUnit\Framework\TestCase;
use Swoole\Http\Response as SwooleResponse;
use Utopia\Http\Adapter\Swoole\Response as UtopiaSwooleResponse;

class BuilderTest extends TestCase
{
    protected ?Response $response = null;

    public function setUp(): void
    {
        Models::init();
        $this->response = new Response(new UtopiaSwooleResponse(new SwooleResponse()));
        Mapper::init(Models::getModels());
    }

    /**
     * @throws \Exception
     */
    public function testCreateTypeMapping()
    {
        $model = Models::getModel(Response::MODEL_COLLECTION);
        $type = Mapper::model(\ucfirst($model->getType()));
        $this->assertEquals('Collection', $type->name);
    }
}
