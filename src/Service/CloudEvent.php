<?php

declare(strict_types=1);

namespace Keel\Service;

use Keel\Http\Exception\BadRequest;
use Psr\Http\Message\ServerRequestInterface;

/**
 * A CloudEvents 1.0 event (cloudevents.io): the vendor-neutral envelope for events between
 * services, understood by Knative, Dapr, Azure Event Grid, Google Eventarc and others.
 */
final readonly class CloudEvent implements \JsonSerializable
{
    public const string MEDIA_TYPE = 'application/cloudevents+json';

    /** @param array<mixed> $data */
    public function __construct(
        public string $id,
        public string $source,
        public string $type,
        public array $data = [],
        public ?string $subject = null,
        public ?string $time = null,
        public string $dataContentType = 'application/json',
    ) {
    }

    /**
     * Reads an event from an HTTP request in structured mode (`application/cloudevents+json`)
     * or binary mode (`ce-*` headers, the data as the body).
     *
     * @throws BadRequest when required attributes are missing
     */
    public static function fromRequest(ServerRequestInterface $request): self
    {
        $body = (string) $request->getBody();
        $contentType = strtolower(trim(explode(';', $request->getHeaderLine('Content-Type'))[0]));
        if ($contentType === self::MEDIA_TYPE) {
            $event = json_decode($body, true, 64);
            if (!\is_array($event)) {
                throw new BadRequest('The CloudEvent is not valid JSON.');
            }
            $attributes = $event;
            $data = $event['data'] ?? [];
            $dataType = \is_string($event['datacontenttype'] ?? null) ? $event['datacontenttype'] : 'application/json';
        } else {
            $attributes = [];
            foreach (['id', 'source', 'type', 'specversion', 'subject', 'time'] as $name) {
                $attributes[$name] = $request->getHeaderLine('ce-' . $name) ?: null;
            }
            $data = $body === '' ? [] : json_decode($body, true, 64);
            $dataType = $contentType === '' ? 'application/json' : $contentType;
        }
        $required = [];
        foreach (['id', 'source', 'type'] as $name) {
            $value = $attributes[$name] ?? null;
            if (!\is_string($value) || $value === '') {
                throw new BadRequest("The CloudEvent has no \"$name\".", ['code' => 'invalid_cloudevent']);
            }
            $required[] = $value;
        }
        if (($attributes['specversion'] ?? null) !== '1.0') {
            throw new BadRequest('Only CloudEvents specversion 1.0 is supported.', ['code' => 'invalid_cloudevent']);
        }

        return new self(
            $required[0],
            $required[1],
            $required[2],
            \is_array($data) ? $data : ['value' => $data],
            \is_string($attributes['subject'] ?? null) ? $attributes['subject'] : null,
            \is_string($attributes['time'] ?? null) ? $attributes['time'] : null,
            $dataType,
        );
    }

    /** @return array<string, mixed> structured mode */
    public function jsonSerialize(): array
    {
        return array_filter([
            'specversion' => '1.0',
            'id' => $this->id,
            'source' => $this->source,
            'type' => $this->type,
            'subject' => $this->subject,
            'time' => $this->time,
            'datacontenttype' => $this->dataContentType,
            'data' => $this->data === [] ? new \stdClass() : $this->data,
        ], static fn (mixed $v): bool => $v !== null);
    }
}
