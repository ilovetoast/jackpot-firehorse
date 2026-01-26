#!/bin/bash

# Test script for subdomain setup
# Run this to verify your wildcard DNS and subdomain routing is working

echo "🧪 Testing Subdomain Setup..."
echo "================================"

# Test DNS resolution
echo "1. Testing DNS Resolution..."
echo -n "   jackpot.local: "
if nslookup jackpot.local >/dev/null 2>&1; then
    IP=$(nslookup jackpot.local | grep "Address:" | tail -n1 | awk '{print $2}')
    if [ "$IP" = "127.0.0.1" ]; then
        echo "✅ $IP"
    else
        echo "❌ $IP (should be 127.0.0.1)"
    fi
else
    echo "❌ Failed to resolve"
fi

echo -n "   test.jackpot.local: "
if nslookup test.jackpot.local >/dev/null 2>&1; then
    IP=$(nslookup test.jackpot.local | grep "Address:" | tail -n1 | awk '{print $2}')
    if [ "$IP" = "127.0.0.1" ]; then
        echo "✅ $IP (wildcard working!)"
    else
        echo "❌ $IP (wildcard not working)"
    fi
else
    echo "❌ Failed to resolve (wildcard not working)"
fi

# Test HTTP connectivity
echo ""
echo "2. Testing HTTP Connectivity..."

# Test main domain
echo -n "   http://jackpot.local: "
if curl -s -o /dev/null -w "%{http_code}" http://jackpot.local | grep -q "200\|302"; then
    echo "✅ Accessible"
else
    echo "❌ Not accessible"
fi

# Test subdomain
echo -n "   http://test-company.jackpot.local: "
if curl -s -o /dev/null -w "%{http_code}" http://test-company.jackpot.local | grep -q "200\|302"; then
    echo "✅ Accessible (subdomain routing working!)"
else
    echo "❌ Not accessible (check nginx/server setup)"
fi

# Check if dnsmasq is running
echo ""
echo "3. Checking DNS Services..."
if command -v systemctl >/dev/null 2>&1; then
    echo -n "   dnsmasq service: "
    if systemctl is-active --quiet dnsmasq; then
        echo "✅ Running"
    else
        echo "❌ Not running"
    fi
fi

# Check DNS configuration
echo ""
echo "4. Checking DNS Configuration..."
if [ -f /etc/resolv.conf ]; then
    echo -n "   /etc/resolv.conf has 127.0.0.1: "
    if grep -q "127.0.0.1" /etc/resolv.conf; then
        echo "✅ Yes"
    else
        echo "❌ No (add 'nameserver 127.0.0.1' to /etc/resolv.conf)"
    fi
fi

echo ""
echo "================================"
echo "🎯 Setup Status Summary:"
echo ""

# Overall assessment
DNS_OK=false
HTTP_OK=false

if nslookup test.jackpot.local >/dev/null 2>&1; then
    IP=$(nslookup test.jackpot.local | grep "Address:" | tail -n1 | awk '{print $2}')
    if [ "$IP" = "127.0.0.1" ]; then
        DNS_OK=true
    fi
fi

if curl -s -o /dev/null -w "%{http_code}" http://test-company.jackpot.local | grep -q "200\|302"; then
    HTTP_OK=true
fi

if [ "$DNS_OK" = true ] && [ "$HTTP_OK" = true ]; then
    echo "✅ Everything looks good! Wildcard subdomains should work."
    echo "   Try: http://your-company-name.jackpot.local"
elif [ "$DNS_OK" = true ]; then
    echo "⚠️  DNS is working but HTTP server needs configuration."
    echo "   Check nginx configuration and restart services."
elif [ "$HTTP_OK" = true ]; then
    echo "⚠️  HTTP is working but wildcard DNS needs setup."
    echo "   Set up dnsmasq or add manual hosts entries."
else
    echo "❌ Both DNS and HTTP need configuration."
    echo "   Follow the setup guide in SUBDOMAIN_SETUP.md"
fi

echo ""
echo "📖 For detailed setup instructions, see: SUBDOMAIN_SETUP.md"