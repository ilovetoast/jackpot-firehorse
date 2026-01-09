import { ArrowUpIcon, ArrowDownIcon, MinusIcon } from '@heroicons/react/24/outline';

export default function CostTrendIndicator({ trend, percentChange }) {
    if (!trend || percentChange === undefined || percentChange === null) {
        return (
            <span className="inline-flex items-center text-sm text-gray-500">
                <MinusIcon className="h-4 w-4 mr-1" />
                No data
            </span>
        );
    }

    const isPositive = percentChange > 0;
    const isNegative = percentChange < 0;
    const isStable = percentChange === 0;

    const getIcon = () => {
        if (isPositive) {
            return <ArrowUpIcon className="h-4 w-4 mr-1" />;
        }
        if (isNegative) {
            return <ArrowDownIcon className="h-4 w-4 mr-1" />;
        }
        return <MinusIcon className="h-4 w-4 mr-1" />;
    };

    const getColor = () => {
        if (isPositive) {
            return 'text-red-600';
        }
        if (isNegative) {
            return 'text-green-600';
        }
        return 'text-gray-600';
    };

    return (
        <span className={`inline-flex items-center text-sm font-medium ${getColor()}`}>
            {getIcon()}
            {Math.abs(percentChange).toFixed(1)}%
        </span>
    );
}
