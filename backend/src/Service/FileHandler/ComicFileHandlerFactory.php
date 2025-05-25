<?php

namespace App\Service\FileHandler;

use App\Service\FileHandler\Interface\ComicFileHandlerInterface;

/**
 * Factory for creating comic file handlers
 * 
 * This service creates the appropriate handler for a given comic file
 */
class ComicFileHandlerFactory
{
    /**
     * @var ComicFileHandlerInterface[]
     */
    private array $handlers;
    
    /**
     * Constructor
     * 
     * @param iterable $handlers Collection of all available comic file handlers
     */
    public function __construct(iterable $handlers)
    {
        $this->handlers = [];
        
        // Convert iterable to array
        foreach ($handlers as $handler) {
            if ($handler instanceof ComicFileHandlerInterface) {
                $this->handlers[] = $handler;
            }
        }
    }
    
    /**
     * Get the appropriate handler for a comic file
     * 
     * @param string $filePath Path to the comic file
     * @return ComicFileHandlerInterface|null The handler or null if no suitable handler found
     */
    public function getHandler(string $filePath): ?ComicFileHandlerInterface
    {
        foreach ($this->handlers as $handler) {
            if ($handler->supports($filePath)) {
                return $handler;
            }
        }
        
        return null;
    }
}
