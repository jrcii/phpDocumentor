<?php

declare(strict_types=1);

/**
 * This file is part of phpDocumentor.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @link http://phpdoc.org
 */

namespace phpDocumentor\Compiler\Pass;

use phpDocumentor\Compiler\CompilerPassInterface;
use phpDocumentor\Descriptor\Collection;
use phpDocumentor\Descriptor\DescriptorAbstract;
use phpDocumentor\Descriptor\PackageDescriptor;
use phpDocumentor\Descriptor\ProjectDescriptor;
use phpDocumentor\Descriptor\TagDescriptor;
use phpDocumentor\Reflection\Fqsen;
use function explode;
use function ltrim;
use function ucfirst;

/**
 * Rebuilds the package tree from the elements found in files.
 *
 * On every compiler pass is the package tree rebuild to aid in the process
 * of incremental updates.
 *
 * If the package tree were to be persisted then both locations needed to be
 * invalidated if a file were to change.
 */
class PackageTreeBuilder implements CompilerPassInterface
{
    public const COMPILER_PRIORITY = 9001;

    public function getDescription() : string
    {
        return 'Build "packages" index';
    }

    public function execute(ProjectDescriptor $project) : void
    {
        $rootPackageDescriptor = new PackageDescriptor();
        $rootPackageDescriptor->setName('\\');
        $project->getIndexes()->set('packages', new Collection());
        $project->getIndexes()->packages['\\'] = $rootPackageDescriptor;

        foreach ($project->getFiles() as $file) {
            $this->addElementsOfTypeToPackage($project, [$file], 'files');
            $this->addElementsOfTypeToPackage($project, $file->getConstants()->getAll(), 'constants');
            $this->addElementsOfTypeToPackage($project, $file->getFunctions()->getAll(), 'functions');
            $this->addElementsOfTypeToPackage($project, $file->getClasses()->getAll(), 'classes');
            $this->addElementsOfTypeToPackage($project, $file->getInterfaces()->getAll(), 'interfaces');
            $this->addElementsOfTypeToPackage($project, $file->getTraits()->getAll(), 'traits');
        }
    }

    /**
     * Adds the given elements of a specific type to their respective Package Descriptors.
     *
     * This method will assign the given elements to the package as registered in the package field of that
     * element. If a package does not exist yet it will automatically be created.
     *
     * @param DescriptorAbstract[] $elements Series of elements to add to their respective package.
     * @param string               $type     Declares which field of the package will be populated with the given
     *                   series of elements. This name will be transformed to a getter which must exist. Out of
     *                   performance considerations will no effort be done to verify whether the provided type is
     *                   valid.
     */
    protected function addElementsOfTypeToPackage(ProjectDescriptor $project, array $elements, string $type) : void
    {
        /** @var DescriptorAbstract $element */
        foreach ($elements as $element) {
            $packageName = '';
            $packageTags = $element->getTags()->get('package');
            if ($packageTags instanceof Collection) {
                $packageTag = $packageTags->getIterator()->current();
                if ($packageTag instanceof TagDescriptor) {
                    $packageName = $packageTag->getDescription();
                }
            }

            $subpackageCollection = $element->getTags()->get('subpackage');
            if ($subpackageCollection instanceof Collection && $subpackageCollection->count() > 0) {
                $subpackageTag = $subpackageCollection->getIterator()->current();
                if ($subpackageTag instanceof TagDescriptor) {
                    $packageName .= '\\' . $subpackageTag->getDescription();
                }
            }

            // ensure consistency by trimming the slash prefix and then re-appending it.
            $packageIndexName = '\\' . ltrim((string) $packageName, '\\');
            if (!isset($project->getIndexes()->packages[$packageIndexName])) {
                $this->createPackageDescriptorTree($project, (string) $packageName);
            }

            /** @var PackageDescriptor $package */
            $package = $project->getIndexes()->packages[$packageIndexName];

            // replace textual representation with an object representation
            $element->setPackage($package);

            // add element to package
            $getter = 'get' . ucfirst($type);

            /** @var Collection $collection */
            $collection = $package->{$getter}();
            $collection->add($element);
        }
    }

    /**
     * Creates a tree of PackageDescriptors based on the provided FQNN (package name).
     *
     * This method will examine the package name and create a package descriptor for each part of
     * the FQNN if it doesn't exist in the packages field of the current package (starting with the root
     * Package in the Project Descriptor),
     *
     * As an intended side effect this method also populates the *elements* index of the ProjectDescriptor with all
     * created PackageDescriptors. Each index key is prefixed with a tilde (~) so that it will not conflict with
     * other FQSEN's, such as classes or interfaces.
     *
     * @see ProjectDescriptor::getPackage() for the root package.
     * @see PackageDescriptor::getChildren() for the child packages of a given package.
     *
     * @param string $packageName A FQNN of the package (and parents) to create.
     */
    protected function createPackageDescriptorTree(ProjectDescriptor $project, string $packageName) : void
    {
        $parts = explode('\\', ltrim($packageName, '\\'));
        $fqnn  = '';

        // this method does not use recursion to traverse the tree but uses a pointer that will be overridden with the
        // next item that is to be traversed (child package) at the end of the loop.

        /** @var PackageDescriptor $pointer */
        $pointer = $project->getIndexes()->packages['\\'];
        foreach ($parts as $part) {
            $fqnn .= '\\' . $part;
            if ($pointer->getChildren()->get($part)) {
                $pointer = $pointer->getChildren()->get($part);
                continue;
            }

            // package does not exist, create it
            $interimPackageDescriptor = new PackageDescriptor();
            $interimPackageDescriptor->setParent($pointer);
            $interimPackageDescriptor->setName($part);
            $interimPackageDescriptor->setFullyQualifiedStructuralElementName(new Fqsen($fqnn));

            // add to the pointer's list of children
            $pointer->getChildren()->set($part ?: 'UNKNOWN', $interimPackageDescriptor);

            // add to index
            $project->getIndexes()->packages[$fqnn] = $interimPackageDescriptor;

            // move pointer forward
            $pointer = $interimPackageDescriptor;
        }
    }
}
