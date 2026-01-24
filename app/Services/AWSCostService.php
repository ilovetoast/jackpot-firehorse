<?php

namespace App\Services;

use Aws\CostExplorer\CostExplorerClient;
use Aws\Exception\AwsException;
use Illuminate\Support\Facades\Log;

/**
 * AWS Cost Service
 * 
 * Fetches AWS costs from AWS Cost Explorer API.
 * 
 * Requires AWS credentials with Cost Explorer permissions:
 * - ce:GetCostAndUsage
 * - ce:GetDimensionValues
 * - ce:GetUsageReport
 * 
 * Note: AWS Cost Explorer API has a 24-hour delay for data availability.
 * 
 * TODO: Cache results to reduce API calls
 * TODO: Add support for historical queries
 * TODO: Add support for filtering by tags
 */
class AWSCostService
{
    protected ?CostExplorerClient $client = null;

    /**
     * Initialize AWS Cost Explorer client.
     */
    protected function getClient(): ?CostExplorerClient
    {
        if ($this->client !== null) {
            return $this->client;
        }

        $key = config('services.aws.key') ?? config('services.ses.key');
        $secret = config('services.aws.secret') ?? config('services.ses.secret');
        $region = config('services.aws.region', 'us-east-1') ?? config('services.ses.region', 'us-east-1');

        if (!$key || !$secret) {
            Log::warning('AWS credentials not configured - cannot fetch cost data');
            return null;
        }

        try {
            $this->client = new CostExplorerClient([
                'version' => 'latest',
                'region' => $region,
                'credentials' => [
                    'key' => $key,
                    'secret' => $secret,
                ],
            ]);

            return $this->client;
        } catch (\Exception $e) {
            Log::error('Failed to initialize AWS Cost Explorer client', [
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Get costs for a specific month.
     * 
     * @param int|null $month Month (1-12), null for current month
     * @param int|null $year Year, null for current year
     * @return array Cost data grouped by service
     */
    public function getMonthlyCosts(?int $month = null, ?int $year = null): array
    {
        $client = $this->getClient();
        if (!$client) {
            return [
                'total' => 0,
                'by_service' => [],
                'error' => 'AWS credentials not configured',
            ];
        }

        $startDate = now()->setMonth($month ?? now()->month)
            ->setYear($year ?? now()->year)
            ->startOfMonth();
        $endDate = $startDate->copy()->endOfMonth();

        // AWS Cost Explorer has a 24-hour delay
        // Don't query current month if it's not complete
        if ($startDate->isCurrentMonth() && $startDate->isBefore(now()->subDay())) {
            $endDate = now()->subDay()->endOfDay();
        }

        try {
            $result = $client->getCostAndUsage([
                'TimePeriod' => [
                    'Start' => $startDate->format('Y-m-d'),
                    'End' => $endDate->addDay()->format('Y-m-d'), // End date is exclusive
                ],
                'Granularity' => 'MONTHLY',
                'Metrics' => ['UnblendedCost'],
                'GroupBy' => [
                    [
                        'Type' => 'DIMENSION',
                        'Key' => 'SERVICE',
                    ],
                ],
            ]);

            $total = 0;
            $byService = [];

            if (isset($result['ResultsByTime']) && count($result['ResultsByTime']) > 0) {
                $timeResult = $result['ResultsByTime'][0];
                
                if (isset($timeResult['Total']['UnblendedCost']['Amount'])) {
                    $total = (float) $timeResult['Total']['UnblendedCost']['Amount'];
                }

                if (isset($timeResult['Groups'])) {
                    foreach ($timeResult['Groups'] as $group) {
                        $serviceName = $group['Keys'][0] ?? 'Unknown';
                        $amount = isset($group['Metrics']['UnblendedCost']['Amount'])
                            ? (float) $group['Metrics']['UnblendedCost']['Amount']
                            : 0;
                        
                        $byService[$serviceName] = $amount;
                    }
                }
            }

            return [
                'total' => round($total, 2),
                'by_service' => $byService,
                'period' => [
                    'month' => $month ?? now()->month,
                    'year' => $year ?? now()->year,
                ],
            ];
        } catch (AwsException $e) {
            // Suppress AccessDeniedException errors (expected when Cost Explorer isn't enabled)
            if ($e->getAwsErrorCode() === 'AccessDeniedException') {
                Log::debug('AWS Cost Explorer access denied (expected if not enabled)', [
                    'error' => $e->getMessage(),
                    'code' => $e->getAwsErrorCode(),
                ]);
            } else {
                Log::error('Failed to fetch AWS costs', [
                    'error' => $e->getMessage(),
                    'code' => $e->getAwsErrorCode(),
                ]);
            }

            return [
                'total' => 0,
                'by_service' => [],
                'error' => $e->getAwsErrorCode() === 'AccessDeniedException'
                    ? 'AWS credentials do not have Cost Explorer permissions'
                    : 'Failed to fetch AWS costs: ' . $e->getMessage(),
            ];
        } catch (\Exception $e) {
            Log::error('Failed to fetch AWS costs', [
                'error' => $e->getMessage(),
            ]);

            return [
                'total' => 0,
                'by_service' => [],
                'error' => 'Failed to fetch AWS costs: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Get costs grouped by service category.
     * 
     * Categories:
     * - storage: S3, EBS, etc.
     * - compute: EC2, Lambda, etc.
     * - database: RDS, DynamoDB, etc.
     * - networking: CloudFront, VPC, etc.
     * - other: Everything else
     * 
     * @param int|null $month
     * @param int|null $year
     * @return array
     */
    public function getMonthlyCostsByCategory(?int $month = null, ?int $year = null): array
    {
        $costs = $this->getMonthlyCosts($month, $year);
        
        if (isset($costs['error'])) {
            return $costs;
        }

        $categories = [
            'storage' => ['Amazon Simple Storage Service', 'Amazon Elastic Block Store', 'Amazon Elastic File System'],
            'compute' => ['Amazon Elastic Compute Cloud - Compute', 'AWS Lambda', 'Amazon Elastic Container Service', 'Amazon Elastic Kubernetes Service'],
            'database' => ['Amazon Relational Database Service', 'Amazon DynamoDB', 'Amazon ElastiCache', 'Amazon Redshift'],
            'networking' => ['Amazon CloudFront', 'Amazon VPC', 'AWS Data Transfer', 'Amazon Route 53'],
            'monitoring' => ['AmazonCloudWatch', 'AWS X-Ray'],
        ];

        $categorized = [
            'storage' => 0,
            'compute' => 0,
            'database' => 0,
            'networking' => 0,
            'monitoring' => 0,
            'other' => 0,
        ];

        foreach ($costs['by_service'] as $service => $amount) {
            $categorizedService = false;
            
            foreach ($categories as $category => $services) {
                if (in_array($service, $services)) {
                    $categorized[$category] += $amount;
                    $categorizedService = true;
                    break;
                }
            }
            
            if (!$categorizedService) {
                $categorized['other'] += $amount;
            }
        }

        // Round all values
        foreach ($categorized as $key => $value) {
            $categorized[$key] = round($value, 2);
        }

        return [
            'total' => $costs['total'],
            'by_category' => $categorized,
            'by_service' => $costs['by_service'],
            'period' => $costs['period'],
        ];
    }
}
