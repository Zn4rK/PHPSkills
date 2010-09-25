<?php
namespace Moserware\Skills\TrueSkill\Factors;

require_once(dirname(__FILE__) . "/GaussianFactor.php");
require_once(dirname(__FILE__) . "/../../FactorGraphs/Message.php");
require_once(dirname(__FILE__) . "/../../FactorGraphs/Variable.php");
require_once(dirname(__FILE__) . "/../../Numerics/GaussianDistribution.php");

use Moserware\Numerics\GaussianDistribution;
use Moserware\Skills\FactorGraphs\Message;
use Moserware\Skills\FactorGraphs\Variable;

/// <summary>
/// Factor that sums together multiple Gaussians.
/// </summary>
/// <remarks>See the accompanying math paper for more details.</remarks>
class GaussianWeightedSumFactor extends GaussianFactor
{
    private $_variableIndexOrdersForWeights = array();

    // This following is used for convenience, for example, the first entry is [0, 1, 2]
    // corresponding to v[0] = a1*v[1] + a2*v[2]
    private $_weights;
    private $_weightsSquared;

    public function __construct(Variable &$sumVariable, array &$variablesToSum, array &$variableWeights = null)
    {
        parent::__construct($this->createName($sumVariable, $variablesToSum, $variableWeights));
        $this->_weights = array();
        $this->_weightsSquared = array();

        // The first weights are a straightforward copy
        // v_0 = a_1*v_1 + a_2*v_2 + ... + a_n * v_n
        $this->_weights[0] = array();

        $variableWeightsLength = count($variableWeights);

        for($i = 0; $i < $variableWeightsLength; $i++)
        {
            $weight = $variableWeights[$i];
            $this->_weights[0][$i] = $weight;
            $this->_weightsSquared[0][i] = $weight * $weight;
        }

        $variablesToSumLength = count($variablesToSum);

        // 0..n-1
        for($i = 0; $i < ($variablesToSumLength + 1); $i++)
        {
            $this->_variableIndexOrdersForWeights[] = $i;
        }
        
        // The rest move the variables around and divide out the constant.
        // For example:
        // v_1 = (-a_2 / a_1) * v_2 + (-a3/a1) * v_3 + ... + (1.0 / a_1) * v_0
        // By convention, we'll put the v_0 term at the end

        $weightsLength = $variableWeightsLength + 1;
        for ($weightsIndex = 1; $weightsIndex < $weightsLength; $weightsIndex++)
        {            
            $this->_weights[$weightsIndex] = array();

            $variableIndices = array();
            $variableIndices[] = $weightsIndex;

            $currentWeightsSquared = array();
            $this->_WeightsSquared[$weightsIndex] = $currentWeightsSquared;

            // keep a single variable to keep track of where we are in the array.
            // This is helpful since we skip over one of the spots
            $currentDestinationWeightIndex = 0;

            for ($currentWeightSourceIndex = 0;
                 $currentWeightSourceIndex < $variableWeights.Length;
                 $currentWeightSourceIndex++)
            {
                if ($currentWeightSourceIndex == ($weightsIndex - 1))
                {
                    continue;
                }

                $currentWeight = (-$variableWeights[$currentWeightSourceIndex]/$variableWeights[$weightsIndex - 1]);

                if ($variableWeights[$weightsIndex - 1] == 0)
                {
                    // HACK: Getting around division by zero
                    $currentWeight = 0;
                }

                $currentWeights[$currentDestinationWeightIndex] = $currentWeight;
                $currentWeightsSquared[$currentDestinationWeightIndex] = $currentWeight*currentWeight;

                $variableIndices[$currentDestinationWeightIndex + 1] = $currentWeightSourceIndex + 1;
                $currentDestinationWeightIndex++;
            }

            // And the final one
            $finalWeight = 1.0/$variableWeights[$weightsIndex - 1];

            if ($variableWeights[$weightsIndex - 1] == 0)
            {
                // HACK: Getting around division by zero
                $finalWeight = 0;
            }
            $currentWeights[$currentDestinationWeightIndex] = finalWeight;
            $currentWeightsSquared[currentDestinationWeightIndex] = finalWeight*finalWeight;
            $variableIndices[$variableIndices.Length - 1] = 0;
            $this->_variableIndexOrdersForWeights[] = $variableIndices;
        }

        $this->createVariableToMessageBinding($sumVariable);

        foreach ($variablesToSum as $currentVariable)
        {
            $this->createVariableToMessageBinding($currentVariable);
        }
    }

    public function getLogNormalization()
    {
        $vars = $this->getVariables();
        $messages = $this->getMessages();

        $result = 0.0;

        // We start at 1 since offset 0 has the sum
        $varCount = count($vars);
        for ($i = 1; $i < $varCount; $i++)
        {
            $result += GaussianDistribution::logRatioNormalization($vars[i]->getValue(), $messages[i]->getValue());
        }

        return $result;
    }

    private function updateHelper(array &$weights, array &$weightsSquared,
                                  array &$messages,
                                  array &$variables)
    {
        // Potentially look at http://mathworld.wolfram.com/NormalSumDistribution.html for clues as
        // to what it's doing

        $messages = $this->getMessages();
        $message0 = clone $messages[0]->getValue();
        $marginal0 = clone $variables[0]->getValue();

        // The math works out so that 1/newPrecision = sum of a_i^2 /marginalsWithoutMessages[i]
        $inverseOfNewPrecisionSum = 0.0;
        $anotherInverseOfNewPrecisionSum = 0.0;
        $weightedMeanSum = 0.0;
        $anotherWeightedMeanSum = 0.0;

        $weightsSquaredLength = count($weightsSquared);

        for ($i = 0; $i < $weightsSquaredLength; $i++)
        {
            // These flow directly from the paper

            $inverseOfNewPrecisionSum += $weightsSquared[i]/
                                         ($variables[$i + 1]->getValue()->getPrecision() - $messages[$i + 1]->getValue()->getPrecision());

            $diff = GaussianDistribution::divide($variables[$i + 1]->getValue(), $messages[$i + 1]->getValue());
            $anotherInverseOfNewPrecisionSum += $weightsSquared[i]/$diff->getPrecision();

            $weightedMeanSum += $weights[i]
                                *
                                ($variables[$i + 1]->getValue()->getPrecisionMean() - $messages[$i + 1]->getValue()->getPrecisionMean())
                                /
                                ($variables[$i + 1]->getValue()->getPrecision() - $messages[$i + 1]->getValue()->getPrecision());

            $anotherWeightedMeanSum += $weights[$i]*$diff->getPrecisionMean()/$diff->getPrecision();
        }

        $newPrecision = 1.0/$inverseOfNewPrecisionSum;
        $anotherNewPrecision = 1.0/$anotherInverseOfNewPrecisionSum;

        $newPrecisionMean = $newPrecision*$weightedMeanSum;
        $anotherNewPrecisionMean = $anotherNewPrecision*$anotherWeightedMeanSum;

        $newMessage = GaussianDistribution::fromPrecisionMean($newPrecisionMean, $newPrecision);
        $oldMarginalWithoutMessage = GaussianDistribution::divide($marginal0, $message0);

        $newMarginal = GaussianDistribution::multiply($oldMarginalWithoutMessage, $newMessage);

        /// Update the message and marginal

        $messages[0]->setValue($newMessage);
        $variables[0]->setValue($newMarginal);

        /// Return the difference in the new marginal
        $finalDiff = GaussianDistribution::subtract($newMarginal, $marginal0);
        return $finalDiff;
    }

    public function updateMessageIndex($messageIndex)
    {
        $allMessages = $this->getMessages();
        $allVariables = $this->getVariables();

        Guard::argumentIsValidIndex($messageIndex, count($allMessages),"messageIndex");

        $updatedMessages = array();
        $updatedVariables = array();

        $indicesToUse = $this->_variableIndexOrdersForWeights[$messageIndex];

        // The tricky part here is that we have to put the messages and variables in the same
        // order as the weights. Thankfully, the weights and messages share the same index numbers,
        // so we just need to make sure they're consistent
        $allMessagesCount = count($allMessages);
        for ($i = 0; i < $allMessagesCount; $i++)
        {
            $updatedMessages[] =$allMessages[$indicesToUse[$i]];
            $updatedVariables[] = $allVariables[$indicesToUse[$i]];
        }

        return updateHelper($this->_weights[$messageIndex],
                            $this->_weightsSquared[$messageIndex],
                            $updatedMessages,
                            $updatedVariables);
    }
}

?>