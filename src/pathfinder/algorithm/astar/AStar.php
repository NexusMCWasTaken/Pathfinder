<?php

declare(strict_types=1);

namespace pathfinder\algorithm\astar;

use pathfinder\algorithm\Algorithm;
use pathfinder\cost\CostCalculator;
use pathfinder\pathpoint\PathPoint;
use pathfinder\pathresult\PathResult;
use pocketmine\block\Slab;
use pocketmine\block\Stair;
use pocketmine\math\Vector3;
use function abs;
use function array_key_first;
use function asort;

class AStar extends Algorithm {
    public const SIDES = [
        [0, 1],
        [1, 0],
        [0, -1],
        [-1, 0],

        [1, 1],
        [-1, 1],
        [1, -1],
        [-1, -1]
    ];

    /** @var Node[]  */
    private array $openList = [];
    /** @var Node[]  */
    private array $closedList = [];

    private ?Node $startNode = null;
    private ?Node $targetNode = null;
    private ?Node $bestNode = null;

    protected function tick(): void{
        $world = $this->getWorld();

        $isInSlabOrStair = false;
        if($this->startNode === null) {
            $this->startNode = Node::fromVector3($this->startVector3);
            $this->startNode->setG(0.0);
            $this->startNode->setH($this->calculateHCost($this->startNode));

            $this->targetNode = Node::fromVector3($this->targetVector3);

            $this->openList[$this->startNode->getHash()] = $this->startNode;

            $startBlock = $world->getBlock($this->startNode);
            if($startBlock instanceof Slab || $startBlock instanceof Stair) {
                $this->startNode->y++;
                $isInSlabOrStair = true;
            }
        }

        while($this->checkTimout()) {
            $key = $this->getLowestFCost();
            if($key === null) break;
            $currentNode = $this->openList[$key];

            unset($this->openList[$currentNode->getHash()]);
            $this->closedList[$currentNode->getHash()] = $currentNode;

            if($currentNode->getHash() === $this->targetNode->getHash()) {//Path found
                $this->targetNode->setParentNode($currentNode);
                $this->stop();
                break;
            }

            foreach(self::SIDES as $SIDE) {
                $side = $currentNode->add($SIDE[0], 0, $SIDE[1]);

                if(!$this->isSafeToStandAt($side)){
                    if($SIDE[0] !== 0 && $SIDE[1] !== 0) continue;

                    //Jump Height Check
                    $success = false;
                    for($y = 0; $y <= ($isInSlabOrStair ?( $this->getJumpHeight() - 1) : $this->getJumpHeight()); ++$y) {
                        if(!$this->isSafeToStandAt($side->add(0, $y, 0))) continue;
                        $side->y += $y;
                        $success = true;
                        break;
                    }
                    if(!$success) {
                        //Fall Distance Check
                        $success = false;
                        for($y = 0; $y <= $this->getFallDistance(); ++$y) {
                            if(!$this->isSafeToStandAt($side->subtract(0, $y, 0))) continue;
                            $side->y -= $y;
                            $success = true;
                            break;
                        }
                        if(!$success) continue;
                    }
                }

                $sideNode = Node::fromVector3($side);
                if(isset($this->closedList[$sideNode->getHash()])) {
                    continue;
                }

                $cost = CostCalculator::getCost($world->getBlock($side->subtract(0, 1, 0)));
                if(!isset($this->openList[$sideNode->getHash()]) || $currentNode->getG() + $cost < $sideNode->getG()) {
                    $sideNode->setG($currentNode->getG() + $cost);
                    $sideNode->setH($this->calculateHCost($side));
                    $sideNode->setParentNode($currentNode);
                    if(!isset($this->openList[$sideNode->getHash()])) {
                        $this->openList[$sideNode->getHash()] = $sideNode;
                    }
                    if($this->bestNode === null || $this->bestNode->getH() > $sideNode->getH()) {
                        $this->bestNode = $sideNode;
                    }
                }
            }
            $isInSlabOrStair = false;
        }
    }

    protected function finish(): void{
        $node = $this->targetNode?->getParentNode();
        if($node === null){
            $node = $this->bestNode;
            if($node === null || $this->isOnlyAcceptFullPath()) {
                return;
            }
        }
        $pathResult = new PathResult($this->getWorld(), $this->startVector3, $this->targetVector3);
        $pathResult->addPathPoint(new PathPoint($this->targetVector3->x, $this->targetVector3->y, $this->targetVector3->z));
        while(true) {
            $node = $node->getParentNode();
            if($node instanceof Node) {
                $pathResult->addPathPoint(new PathPoint($node->x, $node->y, $node->z));
                continue;
            }
            break;
        }
        $this->pathResult = $pathResult;
    }

    private function getLowestFCost(): ?int {
        $openList = [];
        foreach($this->openList as $hash => $node) {
            $openList[$hash] = $node->getF();
        }
        asort($openList);
        return array_key_first($openList);
    }

    private function calculateHCost(Vector3 $vector3): float{
        $targetVector3 = $this->getTargetVector3();
        return abs($vector3->x - $targetVector3->x) + abs($vector3->y - $targetVector3->y) + abs($vector3->z - $targetVector3->z);
    }
}