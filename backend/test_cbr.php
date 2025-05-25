<?php
// Simple script to test CBR file handling

// Find a CBR file in the system
echo "Looking for CBR files:\n";
$comicsDir = __DIR__ . '/public/uploads/comics';
$command = "find $comicsDir -name '*.cbr'";
exec($command, $output, $returnCode);

if ($returnCode === 0 && !empty($output)) {
    echo "Found CBR files:\n";
    foreach ($output as $file) {
        echo "- $file\n";
    }
    
    $cbrFile = $output[0];
    echo "\nTesting with file: $cbrFile\n";
    
    // Test if 7z is available
    echo "\nTesting if 7z is available:\n";
    exec('which 7z', $output7z, $returnCode7z);
    if ($returnCode7z === 0 && !empty($output7z)) {
        echo "7z found: " . $output7z[0] . "\n";
        
        // Try to list contents with 7z
        echo "\nListing contents with 7z:\n";
        $command7z = "7z l \"$cbrFile\"";
        exec($command7z, $output7zList, $returnCode7zList);
        
        if ($returnCode7zList === 0) {
            echo "7z listing successful, output length: " . count($output7zList) . " lines\n";
            
            // Count image files
            $imageCount = 0;
            foreach ($output7zList as $line) {
                if (preg_match('/\.(jpg|jpeg|png|gif|webp)$/i', $line)) {
                    $imageCount++;
                }
            }
            
            echo "Found $imageCount image files in the CBR archive\n";
            
            // Show some of the image filenames
            echo "\nSample image filenames:\n";
            $imageFiles = [];
            foreach ($output7zList as $line) {
                if (preg_match('/\.(jpg|jpeg|png|gif|webp)$/i', $line)) {
                    preg_match('/[^\s]+\.(jpg|jpeg|png|gif|webp)$/i', $line, $matches);
                    if (!empty($matches[0])) {
                        $imageFiles[] = $matches[0];
                    }
                }
            }
            
            // Sort image files naturally
            usort($imageFiles, 'strnatcmp');
            
            // Show up to 5 image filenames
            $sampleCount = min(5, count($imageFiles));
            for ($i = 0; $i < $sampleCount; $i++) {
                echo "- " . $imageFiles[$i] . "\n";
            }
        } else {
            echo "7z listing failed with return code: $returnCode7zList\n";
            if (!empty($output7zList)) {
                echo "Output: " . implode("\n", $output7zList) . "\n";
            }
        }
    } else {
        echo "7z not found\n";
    }
} else {
    echo "No CBR files found or error running find command\n";
    if (!empty($output)) {
        echo "Output: " . implode("\n", $output) . "\n";
    }
}
